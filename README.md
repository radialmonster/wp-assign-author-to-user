# WP Assign Author to User

A WordPress plugin that links anonymous comments to registered users based on matching email addresses.

## Description

When visitors leave comments on your WordPress site without being logged in, those comments are stored with `user_id = 0` even if the email address matches a registered user. This plugin helps you identify and link those anonymous comments to their corresponding user accounts.

## Features

- **Detect Unlinked Comments**: Identifies comments where the email matches a registered user but `user_id = 0`
- **Detect Orphaned Comments**: Finds comments pointing to deleted users (`user_id` points to non-existent user)
- **Bulk or Individual Linking**: Link comments individually or by email address
- **Create New Users**: Option to create a new user account when linking comments with no existing match
- **Email-based Grouping**: Groups comments by email address with comment counts
- **Safe Updates**: Only updates comments that are not spam or trash

## Installation

1. Download the plugin
2. Upload to `/wp-content/plugins/wp-assign-author-to-user/`
3. Activate through the 'Plugins' menu in WordPress
4. Navigate to Users → Assign Comments in the WordPress admin

## Usage

### Link Comments to Existing User

1. Go to **Users → Assign Comments**
2. Find the email address you want to link
3. Click **Link to existing user**
4. Select the user from the dropdown
5. Click **Link Comments**

### Create New User and Link Comments

1. Go to **Users → Assign Comments**
2. Find the email address you want to link
3. Click **Create new user**
4. Fill in the username and name
5. Click **Create User and Link Comments**

### Filter View

- **Show All**: Display all email addresses with comments
- **Show Unlinked Only**: Display only emails with unlinked or orphaned comments

## What Gets Updated

When you link comments to a user, the plugin:
- Updates `user_id` in `wp_comments` table
- Only affects comments with `comment_approved NOT IN ('spam','trash')`
- Skips comments already correctly linked to the target user
- Fixes orphaned comments (pointing to deleted users)

## Requirements

- WordPress 5.0 or higher
- PHP 7.0 or higher

## Changelog

### 1.0.0
- Initial release
- Link anonymous comments to users by email
- Create new users and link comments
- Detect and fix orphaned comments

## License

This plugin is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation.
