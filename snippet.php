/**
 * Plugin Name: Media Usage Tracker
 * Plugin URI: https://example.com/media-usage-tracker
 * Description: Displays a list of all posts and pages where a media file is used or attached.
 * Version: 1.0
 * Author: Your Name
 * Author URI: https://example.com
 * License: GPL2
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class Media_Usage_Tracker {
    
    public function __construct() {
        // Add column to media library list
        add_filter('manage_media_columns', array($this, 'add_media_columns'));
        add_action('manage_media_custom_column', array($this, 'display_media_column_content'), 10, 2);
        
        // Add meta box to media edit page
        add_action('add_meta_boxes', array($this, 'add_media_meta_box'));
    }
    
    /**
     * Add custom column to media library
     */
    public function add_media_columns($columns) {
        $columns['usage_locations'] = 'Used In';
        return $columns;
    }
    
    /**
     * Display content for custom column
     */
    public function display_media_column_content($column_name, $post_id) {
        if ('usage_locations' !== $column_name) {
            return;
        }
        
        echo $this->get_usage_html($post_id);
    }
    
    /**
     * Add meta box to media edit page
     */
    public function add_media_meta_box() {
        add_meta_box(
            'media_usage_locations',
            'Used In',
            array($this, 'display_media_meta_box'),
            'attachment',
            'side',
            'high'
        );
    }
    
    /**
     * Display meta box content
     */
    public function display_media_meta_box($post) {
        echo $this->get_usage_html($post->ID);
    }
    
    /**
     * Get HTML for usage locations
     */
    public function get_usage_html($attachment_id) {
        $attachment = get_post($attachment_id);
        if (!$attachment) {
            return '<p>No usage found.</p>';
        }
        
        $filename = basename($attachment->guid);
        $attached_to = get_post($attachment->post_parent);
        
        // Find where the media is used
        $usage_locations = $this->find_media_usage($filename, $attachment_id);
        
        if (empty($usage_locations)) {
            return '<p>No usage found.</p>';
        }
        
        // Group by status
        $grouped_locations = array();
        foreach ($usage_locations as $location) {
            $status = get_post_status_object($location['status']);
            $status_name = $status ? $status->label : ucfirst($location['status']);
            
            if (!isset($grouped_locations[$status_name])) {
                $grouped_locations[$status_name] = array();
            }
            
            $grouped_locations[$status_name][] = $location;
        }
        
        $output = '<div class="media-usage-list">';
        
        foreach ($grouped_locations as $status => $locations) {
            $count = count($locations);
            $output .= '<h4>' . esc_html($status) . ' (' . $count . ')</h4>';
            $output .= '<ul>';
            
            foreach ($locations as $location) {
                $edit_url = get_edit_post_link($location['id']);
                $view_url = get_permalink($location['id']);
                $post_type = get_post_type_object($location['type']);
                $type_label = $post_type ? $post_type->labels->singular_name : ucfirst($location['type']);
                
                $output .= '<li>';
                $output .= '<strong>' . esc_html($type_label) . ':</strong> ';
                $output .= '<a href="' . esc_url($edit_url) . '" target="_blank">' . esc_html($location['title']) . '</a> ';
                if ($view_url) {
                    $output .= '<a href="' . esc_url($view_url) . '" target="_blank">(View)</a>';
                }
                $output .= '</li>';
            }
            
            $output .= '</ul>';
        }
        
        $output .= '</div>';
        
        return $output;
    }
    
    /**
     * Find all posts and pages where the media file is used
     */
    public function find_media_usage($filename, $attachment_id) {
        global $wpdb;
        
        $usage_locations = array();
        
        // Get all posts and pages (excluding revisions and inherit status)
        $posts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_title, post_type, post_status 
                FROM $wpdb->posts 
                WHERE post_type NOT IN ('revision', 'attachment') 
                AND post_status != 'inherit'"
            )
        );
        
        if (!$posts) {
            return $usage_locations;
        }
        
        // Check for attachments
        $attached_posts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID, p.post_title, p.post_type, p.post_status 
                FROM $wpdb->posts p 
                JOIN $wpdb->posts a ON p.ID = a.post_parent 
                WHERE a.ID = %d 
                AND p.post_type NOT IN ('revision') 
                AND p.post_status != 'inherit'",
                $attachment_id
            )
        );
        
        // Add attached posts to usage locations
        if ($attached_posts) {
            foreach ($attached_posts as $post) {
                $usage_locations[] = array(
                    'id' => $post->ID,
                    'title' => $post->post_title,
                    'type' => $post->post_type,
                    'status' => $post->post_status
                );
            }
        }
        
        // Check content of each post/page for the filename
        foreach ($posts as $post) {
            // Skip if already added as an attachment
            $already_added = false;
            foreach ($usage_locations as $location) {
                if ($location['id'] == $post->ID) {
                    $already_added = true;
                    break;
                }
            }
            
            if ($already_added) {
                continue;
            }
            
            // Get post content
            $content = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT post_content FROM $wpdb->posts WHERE ID = %d",
                    $post->ID
                )
            );
            
            // Check if filename exists in content
            if (strpos($content, $filename) !== false) {
                $usage_locations[] = array(
                    'id' => $post->ID,
                    'title' => $post->post_title,
                    'type' => $post->post_type,
                    'status' => $post->post_status
                );
                continue;
            }
            
            // Check post meta for custom fields that might contain the filename
            $meta_values = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT meta_value FROM $wpdb->postmeta WHERE post_id = %d",
                    $post->ID
                )
            );
            
            foreach ($meta_values as $meta_value) {
                if (is_serialized($meta_value)) {
                    $unserialized = maybe_unserialize($meta_value);
                    $meta_string = $this->recursive_implode($unserialized);
                    if (strpos($meta_string, $filename) !== false) {
                        $usage_locations[] = array(
                            'id' => $post->ID,
                            'title' => $post->post_title,
                            'type' => $post->post_type,
                            'status' => $post->post_status
                        );
                        break;
                    }
                } elseif (strpos($meta_value, $filename) !== false) {
                    $usage_locations[] = array(
                        'id' => $post->ID,
                        'title' => $post->post_title,
                        'type' => $post->post_type,
                        'status' => $post->post_status
                    );
                    break;
                }
            }
        }
        
        return $usage_locations;
    }
    
    /**
     * Helper function to recursively implode arrays for searching
     */
    private function recursive_implode($array) {
        $result = '';
        
        foreach ($array as $item) {
            if (is_array($item)) {
                $result .= $this->recursive_implode($item) . ' ';
            } else {
                $result .= $item . ' ';
            }
        }
        
        return $result;
    }
}

// Initialize the plugin
new Media_Usage_Tracker();
