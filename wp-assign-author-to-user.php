<?php
/*
Plugin Name: WP Assign Author to User
Description: Group comments by email and assign them to WordPress users (create users if needed).
Version: 1.2
Author: RadialMonster
Plugin URI: https://github.com/radialmonster/wp-assign-author-to-user
Text Domain: wp-assign-author-to-user
*/

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', function () {
    add_comments_page(
        'Assign Author to User',
        'Assign Author to User',
        'manage_options',
        'wp-assign-author-to-user',
        'waatu_render_admin_page'
    );
});

/**
 * Enqueue admin scripts and add inline JS for the plugin page.
 */
function waatu_enqueue_admin_scripts($hook) {
    if ($hook !== 'comments_page_wp-assign-author-to-user') {
        return;
    }

    $script = "
        function toggleComments(rowId) {
            var row = document.getElementById(rowId);
            if (row) {
                if (row.style.display === 'none') {
                    row.style.display = 'table-row';
                } else {
                    row.style.display = 'none';
                }
            }
        }

        function dismissInfoBox() {
            var box = document.getElementById('waatu-info-box');
            var showBtn = document.getElementById('waatu-show-info-btn');
            if (box && showBtn) {
                box.style.display = 'none';
                showBtn.style.display = 'inline-block';
                localStorage.setItem('waatu_info_box_dismissed', 'true');
            }
        }

        function showInfoBox() {
            var box = document.getElementById('waatu-info-box');
            var showBtn = document.getElementById('waatu-show-info-btn');
            if (box && showBtn) {
                box.style.display = 'block';
                showBtn.style.display = 'none';
                localStorage.setItem('waatu_info_box_dismissed', 'false');
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            var dismissed = localStorage.getItem('waatu_info_box_dismissed');
            var box = document.getElementById('waatu-info-box');
            var showBtn = document.getElementById('waatu-show-info-btn');

            if (dismissed === 'true' && box && showBtn) {
                box.style.display = 'none';
                showBtn.style.display = 'inline-block';
            } else if (showBtn) {
                showBtn.style.display = 'none';
            }
        });
    ";
    wp_add_inline_script('jquery-core', $script);
}
add_action('admin_enqueue_scripts', 'waatu_enqueue_admin_scripts');

/**
 * PROCESS ACTIONS (Hooked to admin_init)
 * This runs before the page is drawn, allowing redirects to work.
 */
function waatu_handle_actions() {
    // Check if we are on the admin side and if the specific POST variables exist
    if (!is_admin() || empty($_POST['waatu_action']) || empty($_POST['waatu_email'])) {
        return;
    }

    // Verify permissions
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    // Verify Security Nonce
    check_admin_referer('waatu_assign_action');

    global $wpdb;

    $message = '';
    $email       = sanitize_email(wp_unslash($_POST['waatu_email']));
    $action      = sanitize_text_field(wp_unslash($_POST['waatu_action']));
    $displayname = isset($_POST['waatu_display_name']) ? sanitize_text_field(wp_unslash($_POST['waatu_display_name'])) : '';
    $redirect_page = isset($_POST['paged']) ? max(1, intval($_POST['paged'])) : 1;

    if ($email) {
        if ($action === 'link_existing') {
            $user = get_user_by('email', $email);
            if ($user) {
                $check_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_author_email = %s AND comment_approved NOT IN ('spam','trash')", $email));
                $already_linked = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_author_email = %s AND user_id = %d AND comment_approved NOT IN ('spam','trash')", $email, $user->ID));

                // Update comments: link unlinked (user_id=0), wrongly linked, AND orphaned (user_id points to deleted user)
                $rows = $wpdb->query($wpdb->prepare(
                    "UPDATE {$wpdb->comments} c
                    LEFT JOIN {$wpdb->users} u ON c.user_id = u.ID
                    SET c.user_id = %d
                    WHERE c.comment_author_email = %s
                    AND (c.user_id = 0 OR c.user_id != %d OR u.ID IS NULL)
                    AND c.comment_approved NOT IN ('spam','trash')",
                    $user->ID, $email, $user->ID
                ));
                
                if ($rows === false) {
                    $message = 'Error linking comments to existing user. SQL Error: ' . $wpdb->last_error;
                } else {
                    if ($already_linked > 0 && $rows == 0) {
                        $message = sprintf('All %d comment(s) with %s are already linked to user %s (ID %d).', $already_linked, esc_html($email), esc_html($user->display_name), $user->ID);
                    } else {
                        $message = sprintf('Found %d comment(s) matching email. Linked %d comment(s) with %s to existing user %s. (%d already linked)', $check_count, $rows, esc_html($email), esc_html($user->display_name), $already_linked);
                    }
                }
            } else {
                $message = 'No existing user found with that email.';
            }
        } elseif ($action === 'create_new') {
            if (email_exists($email)) {
                $message = 'A user with this email already exists. Use "Link to existing user" instead.';
            } else {
                if (empty($displayname)) $displayname = $email;
                $base_username = sanitize_user($email, true);
                if (empty($base_username)) $base_username = 'user_' . wp_generate_password(6, false, false);
                $username = $base_username;
                $i = 1;
                while (username_exists($username)) {
                    $username = $base_username . '_' . $i++;
                }
                
                $password = wp_generate_password(16, true, true);
                // Suppress notifications
                add_filter('send_password_change_email', '__return_false');
                add_filter('send_email_change_email', '__return_false');
                add_filter('wp_send_new_user_notifications', '__return_false');
                
                $user_id = wp_create_user($username, $password, $email);
                
                remove_filter('send_password_change_email', '__return_false');
                remove_filter('send_email_change_email', '__return_false');
                remove_filter('wp_send_new_user_notifications', '__return_false');

                if (is_wp_error($user_id)) {
                    $message = 'Error creating user: ' . $user_id->get_error_message();
                } else {
                    wp_update_user(['ID' => $user_id, 'display_name' => $displayname, 'nickname' => $displayname, 'role' => 'subscriber']);
                    $rows = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->comments} SET user_id = %d WHERE comment_author_email = %s AND user_id = 0 AND comment_approved NOT IN ('spam','trash')", $user_id, $email));
                    if ($rows === false) {
                        $message = sprintf('Created user "%s" (%s) but ERROR assigning comments: %s', esc_html($displayname), esc_html($email), esc_html($wpdb->last_error));
                    } else {
                        $message = sprintf('Created user "%s" (%s) and assigned %d comment(s).', esc_html($displayname), esc_html($email), $rows);
                    }
                }
            }
        }
    } else {
        $message = 'Invalid email address.';
    }

    // Save message to transient for the next page load
    set_transient('waatu_message_' . get_current_user_id(), $message, 45);
    
    // Build redirect URL
    $redirect_args = ['page' => 'wp-assign-author-to-user', 'paged' => $redirect_page];
    if (isset($_GET['orderby'])) $redirect_args['orderby'] = sanitize_text_field($_GET['orderby']);
    if (isset($_GET['order'])) $redirect_args['order'] = sanitize_text_field($_GET['order']);
    if (isset($_GET['filter'])) $redirect_args['filter'] = sanitize_text_field($_GET['filter']);

    // Perform Redirect
    wp_redirect(add_query_arg($redirect_args, admin_url('edit-comments.php')));
    exit;
}
// Hook the logic function to admin_init
add_action('admin_init', 'waatu_handle_actions');


/**
 * RENDER PAGE (UI Only)
 */
function waatu_render_admin_page()
{
    if (!current_user_can('manage_options')) {
        wp_die('You do not have permission to access this page.');
    }

    global $wpdb;

    $per_page = 50;
    $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($current_page - 1) * $per_page;

    // Retrieve the message from transient (if any)
    $message = '';
    $transient_message = get_transient('waatu_message_' . get_current_user_id());
    if ($transient_message) {
        $message = $transient_message;
        delete_transient('waatu_message_' . get_current_user_id());
    }

    $orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'count';
    $order = isset($_GET['order']) ? sanitize_text_field($_GET['order']) : 'desc';
    $filter = isset($_GET['filter']) ? sanitize_text_field($_GET['filter']) : 'all';
    $valid_orderby = ['email', 'count', 'name'];
    $valid_order = ['asc', 'desc'];
    $valid_filter = ['all', 'unlinked'];
    if (!in_array($orderby, $valid_orderby)) $orderby = 'count';
    if (!in_array($order, $valid_order)) $order = 'desc';
    if (!in_array($filter, $valid_filter)) $filter = 'all';

    // Build queries based on filter
    $join_clause = "";
    $having_clause = "";

    if ($filter === 'unlinked') {
        // Filter logic:
        // 1. Email has NO associated user (u.ID IS NULL)
        // 2. OR Email HAS user, but some comments are not linked to that user (c.user_id != u.ID OR c.user_id = 0)
        // Note: We join users on email. If c.user_id points to a different user (or 0), it's unlinked/mismatched.
        
        $join_clause = "LEFT JOIN {$wpdb->users} u ON c.comment_author_email = u.user_email";
        $having_clause = "HAVING existing_user_id IS NULL OR SUM(CASE WHEN c.user_id != existing_user_id OR c.user_id = 0 THEN 1 ELSE 0 END) > 0";
    }

    // 1. Get Total Count (Filtered)
    // For complex HAVING clauses, a subquery is safest for counting groups
    $count_sql = "
        SELECT COUNT(*) FROM (
            SELECT c.comment_author_email, MAX(u.ID) as existing_user_id
            FROM {$wpdb->comments} c
            {$join_clause}
            WHERE c.comment_author_email != '' AND c.comment_approved NOT IN ('spam','trash')
            GROUP BY c.comment_author_email
            {$having_clause}
        ) as temp_table
    ";
    $total_emails = (int) $wpdb->get_var($count_sql);


    // 2. Get Paginated Results
    $select_clause = "
        c.comment_author_email AS email, 
        COUNT(*) AS comment_count, 
        GROUP_CONCAT(DISTINCT c.comment_author ORDER BY c.comment_author SEPARATOR ' | ') AS names,
        MAX(u.ID) as existing_user_id
    ";
    
    // Ensure we join for the main query if we need it for selection or filtering
    if (empty($join_clause)) {
        $join_clause = "LEFT JOIN {$wpdb->users} u ON c.comment_author_email = u.user_email";
    }

    $order_clause = "comment_count " . strtoupper($order);
    if ($orderby === 'email') $order_clause = "c.comment_author_email " . strtoupper($order);
    if ($orderby === 'name') {
        $select_clause .= ", MIN(c.comment_author) AS first_name";
        $order_clause = "first_name " . strtoupper($order);
    }

    $limit = absint($per_page);
    $offset_val = absint($offset);

    $sql = "
        SELECT {$select_clause} 
        FROM {$wpdb->comments} c
        {$join_clause}
        WHERE c.comment_author_email != '' AND c.comment_approved NOT IN ('spam','trash') 
        GROUP BY c.comment_author_email 
        {$having_clause}
        ORDER BY {$order_clause} 
        LIMIT {$limit} OFFSET {$offset_val}
    ";
    
    $groups = $wpdb->get_results($sql);

    $total_pages = max(1, ceil($total_emails / $per_page));

    // Helper for sorting links
    function waatu_sortable_column_header($key, $label, $current_orderby, $current_order, $current_page, $current_filter) {
        $new_order = ($current_orderby === $key && $current_order === 'asc') ? 'desc' : 'asc';
        $arrow = $current_orderby === $key ? ($current_order === 'asc' ? ' ▲' : ' ▼') : '';
        $url = add_query_arg(['page' => 'wp-assign-author-to-user', 'orderby' => $key, 'order' => $new_order, 'paged' => $current_page, 'filter' => $current_filter], admin_url('edit-comments.php'));
        return sprintf('<a href="%s" style="text-decoration: none; color: inherit; font-weight: bold;">%s%s</a>', esc_url($url), esc_html($label), $arrow);
    }

    ?>
    <div class="wrap">
        <h1>Assign Author to User</h1>

        <div style="margin: 15px 0;">
            <button type="button" id="waatu-show-info-btn" onclick="showInfoBox()" class="button" style="display: none; margin-right: 10px;">
                Show Instructions
            </button>

            <span style="display: inline-block; margin-right: 10px;">
                <strong>Filter:</strong>
            </span>
            <a href="<?php echo esc_url(add_query_arg(['page' => 'wp-assign-author-to-user', 'filter' => 'all', 'orderby' => $orderby, 'order' => $order], admin_url('edit-comments.php'))); ?>"
               class="button <?php echo $filter === 'all' ? 'button-primary' : ''; ?>">
                Show All
            </a>
            <a href="<?php echo esc_url(add_query_arg(['page' => 'wp-assign-author-to-user', 'filter' => 'unlinked', 'orderby' => $orderby, 'order' => $order], admin_url('edit-comments.php'))); ?>"
               class="button <?php echo $filter === 'unlinked' ? 'button-primary' : ''; ?>">
                Show Unlinked Only
            </a>
        </div>

        <div id="waatu-info-box" style="position: relative; background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid #2271b1; padding: 20px; margin: 20px 0; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
            <button type="button" onclick="dismissInfoBox()" style="position: absolute; top: 10px; right: 10px; background: none; border: none; cursor: pointer; padding: 5px; font-size: 16px; line-height: 1; color: #787c82;" aria-label="Dismiss">
                <span aria-hidden="true">&times;</span>
            </button>
            <h2 style="margin-top: 0;">What This Plugin Does</h2>
            <p>This plugin helps you connect anonymous comments to WordPress user accounts. When visitors leave comments on your site, WordPress stores their name and email but doesn't create user accounts for them. This tool allows you to:</p>
            <ul style="list-style: disc; margin-left: 20px;">
                <li>View all comments grouped by commenter email address</li>
                <li>Link existing comments to registered WordPress users</li>
                <li>Create new WordPress user accounts from commenter emails and automatically assign their comments</li>
            </ul>

            <h3>How to Use This Tool</h3>
            <ol style="list-style: decimal; margin-left: 20px;">
                <li><strong>Review the list below:</strong> Each row shows a unique email address, the number of comments from that email, and the names used when commenting.</li>
                <li><strong>Click the comment count</strong> to expand and view all comments from that email address.</li>
                <li><strong>Choose an action:</strong>
                    <ul style="list-style: circle; margin-left: 20px; margin-top: 10px;">
                        <li><strong>Link to existing user:</strong> If a WordPress user already exists with that email, click this button to associate all their comments with their user account.</li>
                        <li><strong>Create new user:</strong> If no user exists with that email, you can create a new WordPress account (as a Subscriber) and automatically assign all their comments to it. Edit the display name before creating if needed.</li>
                    </ul>
                </li>
                <li><strong>Sort and paginate:</strong> Click column headers to sort by email, comment count, or name. Use the Previous/Next buttons to navigate through pages.</li>
            </ol>

            <p style="margin-bottom: 0;"><strong>Note:</strong> This plugin only processes approved comments (spam and trashed comments are excluded).</p>
        </div>

        <?php if (!empty($message)) : ?>
            <div class="notice notice-info is-dismissible"><p><?php echo esc_html($message); ?></p></div>
        <?php endif; ?>

        <p>Showing page <?php echo (int) $current_page; ?> of <?php echo (int) $total_pages; ?>. Total unique emails: <?php echo (int) $total_emails; ?>. <?php if ($filter === 'unlinked') echo '<strong>(Filtered to unlinked only)</strong>'; ?></p>

        <?php if ($total_pages > 1): ?>
            <p>
                <?php if ($current_page > 1): ?> <a class="button" href="<?php echo esc_url(add_query_arg(['paged' => $current_page - 1, 'orderby' => $orderby, 'order' => $order, 'filter' => $filter])); ?>">Previous</a> <?php endif; ?>
                <?php if ($current_page < $total_pages): ?> <a class="button" href="<?php echo esc_url(add_query_arg(['paged' => $current_page + 1, 'orderby' => $orderby, 'order' => $order, 'filter' => $filter])); ?>">Next</a> <?php endif; ?>
            </p>
        <?php endif; ?>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php echo waatu_sortable_column_header('email', 'Email', $orderby, $order, $current_page, $filter); ?></th>
                    <th><?php echo waatu_sortable_column_header('count', 'Comment count', $orderby, $order, $current_page, $filter); ?></th>
                    <th><?php echo waatu_sortable_column_header('name', 'Names used', $orderby, $order, $current_page, $filter); ?></th>
                    <th>User status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($groups) : foreach ($groups as $row) :
                $email = $row->email;
                $names = $row->names;
                $count = (int) $row->comment_count;
                
                // We can use the ID from the query if available, but get_user_by is cached and safe for getting the full object
                $user = get_user_by('email', $email); 
                $row_id = 'comments-' . md5($email);
                
                // Calculate unlinked count for the "Action" column logic
                $unlinked_count = 0;
                if ($user) {
                     // We can query this efficiently or rely on the fact that if we are in 'unlinked' view, we know there are some.
                     // But for the "Link" button text, we need the exact count of *unlinked* items.
                     $unlinked_count = (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_author_email = %s AND (user_id = 0 OR user_id != %d) AND comment_approved NOT IN ('spam','trash')",
                        $email, $user->ID
                    ));
                } else {
                    $unlinked_count = (int) $count;
                }
            ?>
                <tr>
                    <td><?php echo esc_html($email); ?></td>
                    <td><a href="#" onclick="toggleComments('<?php echo esc_js($row_id); ?>'); return false;" style="text-decoration: none; color: #2271b1; font-weight: bold;"><?php echo esc_html($count); ?> ▼</a></td>
                    <td><?php echo esc_html($names); ?></td>
                    <td><?php if ($user): ?> Existing user: <a href="<?php echo esc_url(admin_url('user-edit.php?user_id=' . $user->ID)); ?>" target="_blank"><?php echo esc_html($user->display_name); ?> (ID <?php echo (int) $user->ID; ?>) ↗</a> <?php else: ?> No user with this email. <?php endif; ?></td>
                    <td>
                        <form method="post" style="margin:0;">
                            <?php wp_nonce_field('waatu_assign_action'); ?>
                            <input type="hidden" name="waatu_email" value="<?php echo esc_attr($email); ?>">
                            <input type="hidden" name="paged" value="<?php echo (int) $current_page; ?>">
                            <!-- Preserve order args in the form if needed, though the redirect handles generic args -->
                            
                            <?php if ($user): ?>
                                <?php if ($unlinked_count > 0): ?>
                                    <button type="submit" class="button" name="waatu_action" value="link_existing" onclick="return confirm('Link all comments from <?php echo esc_js($email); ?> to this user?');">Link to existing user</button>
                                    <br><small>This will assign <?php echo (int) $unlinked_count; ?> comment(s) to this user.</small>
                                <?php else: ?>
                                    <span style="color: green;">✓ All comments linked</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <label>Display name:
                                <?php
                                $first_name = $names;
                                if (strpos($names, ' | ') !== false) {
                                    $parts = explode(' | ', $names);
                                    $first_name = $parts[0];
                                }
                                ?>
                                <input type="text" name="waatu_display_name" value="<?php echo esc_attr($first_name); ?>" style="width: 100%; max-width: 250px;" required>
                                </label><br>
                                <button type="submit" class="button button-primary" name="waatu_action" value="create_new" onclick="return confirm('Create a new user for <?php echo esc_js($email); ?>?');">Create new user</button>
                            <?php endif; ?>
                        </form>
                    </td>
                </tr>
                <tr id="<?php echo esc_attr($row_id); ?>" style="display: none;">
                    <td colspan="5" style="background-color: #f9f9f9; padding: 20px;">
                        <h3>Comments from <?php echo esc_html($email); ?></h3>
                        <?php
                        $comments = get_comments(['author_email' => $email, 'status' => 'approve', 'orderby' => 'comment_date', 'order' => 'DESC']);
                        if ($comments) : ?>
                            <table class="widefat" style="margin-top: 10px;">
                                <thead><tr><th style="width: 12%;">Date</th><th style="width: 35%;">Comment</th><th style="width: 25%;">Post</th><th style="width: 18%;">Author Name</th><th style="width: 10%;">Edit</th></tr></thead>
                                <tbody>
                                    <?php foreach ($comments as $comment) :
                                        $post = get_post($comment->comment_post_ID);
                                        $post_title = $post ? $post->post_title : 'Unknown';
                                        $post_link = $post ? get_permalink($comment->comment_post_ID) . '#comment-' . $comment->comment_ID : '';
                                        $edit_link = admin_url('comment.php?action=editcomment&c=' . $comment->comment_ID);
                                    ?>
                                    <tr>
                                        <td><?php echo esc_html(date('Y-m-d H:i', strtotime($comment->comment_date))); ?></td>
                                        <td style="white-space: pre-wrap;"><?php echo esc_html($comment->comment_content); ?></td>
                                        <td><?php if ($post_link) : ?><a href="<?php echo esc_url($post_link); ?>" target="_blank"><?php echo esc_html($post_title); ?> ↗</a><?php else: echo esc_html($post_title); endif; ?></td>
                                        <td><?php echo esc_html($comment->comment_author); ?></td>
                                        <td><a href="<?php echo esc_url($edit_link); ?>" target="_blank" class="button button-small">Edit ↗</a></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?><p>No comments found.</p><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; else : ?>
                <tr><td colspan="5">No comments found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>

        <?php if ($total_pages > 1): ?>
            <p>
                <?php if ($current_page > 1): ?> <a class="button" href="<?php echo esc_url(add_query_arg(['paged' => $current_page - 1, 'orderby' => $orderby, 'order' => $order, 'filter' => $filter])); ?>">Previous</a> <?php endif; ?>
                <?php if ($current_page < $total_pages): ?> <a class="button" href="<?php echo esc_url(add_query_arg(['paged' => $current_page + 1, 'orderby' => $orderby, 'order' => $order, 'filter' => $filter])); ?>">Next</a> <?php endif; ?>
            </p>
        <?php endif; ?>
    </div>
    <?php
}