# BuddyPress for VIP Go

**Contributors:** humanmade, djpaul, garyj  
**Tags:** BuddyPress, BuddyBoss, WordPress VIP  
**Requires at least:** 4.4.2  
**Tested up to:** 6.8  
**Stable tag:** 1.0.4  
**License:** GPLv2 or later  
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html

Makes BuddyPress' media work with WordPress VIP's hosting.

## Description

This plugin ensures BuddyPress media functionality works correctly on WordPress VIP's hosting environment by:

- Routing all media uploads through VIP's secure file system, bypassing WordPress's default upload directory
- Implementing VIP's security model for file access control and permissions
- Optimizing media delivery through VIP's CDN and caching infrastructure
- Ensuring proper integration with VIP's file storage and retrieval services

The plugin is essential for any BuddyPress site running on WordPress VIP, as it ensures media uploads, avatars, and other BuddyPress media features work reliably and securely in the VIP environment.

### Implementation

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/Automattic/BuddyPress-VIP-Go)

Through the use of this plugin, profile and cover images are handled differently than the default.

Profile and cover images for users and groups are stored in user and group metadata.

- `vipbp-avatars` stores user avatars. It contains serialised data of four crop values, ui_width, URL, and filename keys.
- `vipbp-user-cover` contains only a serialised URL key and value.
- `vipbp-group-avatars` stores group avatars. It contains serialised data of four crop values, ui_width, URL, and filename keys. 
- `vipbp-group-cover` contains only a serialised URL key and value.

Webcam captures for user profiles are handled in the same way.

For cropping, instead of creating a new image, we store the cropping coordinates and later let the Files Service dynamically crop the image on-demand via Photon-like query parameters.

Deleting files is handled by deleting the meta data, and then calling `wp_delete_file()` with the previously stored URL.

### Additional Integrations

- Temporary directories created for group video uploads are cleaned up using WordPress's WP_Filesystem via a custom override. This ensures compatibility and security on VIP Go, replacing the default BuddyPress temp directory removal logic.
- After a video is moved to a group album, the plugin flushes the BuddyPress media cache to ensure that any cached media data is immediately updated. This is handled automatically via a custom action hook.

## Installation

1. Upload the `buddypress-vip-go` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. No additional configuration is required

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for a complete list of changes.

## License

This plugin is licensed under the GPLv2 or later. See [LICENSE](LICENSE) for details.
