=== CLKT - Save Forever, Collect Together ===
Contributors: davidlockie, divydovy
Tags: arweave, backup, decentralized, web3, permanent-storage
Requires at least: 5.8
Tested up to: 6.9
Stable tag: 1.0.19
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Permanently preserve WordPress posts and media on Arweave with one-click decentralized storage.

== Description ==

CLKT connects your WordPress site to permanent, decentralized storage on Arweave. Never worry about losing your content again - no server crashes, no platform shutdowns, no expired subscriptions can touch your work once it's saved with CLKT.

**Key Features:**

* **One-Click Permanent Storage** - Save any post or page to the Arweave permaweb instantly
* **Attachment Support** - Automatically includes images and media with your content
* **Version History** - Every save creates a permanent snapshot you can always access
* **Bulk Upload** - Save your entire content library in one go
* **Self-Managed Wallet** - Connect your own Arweave wallet (Wander) for full control
* **100% WordPress Compatible** - Works with any theme or plugin
* **No Recurring Fees** - Pay once, store forever (small one-time network fee)

**Why CLKT?**

Traditional backups fail. Servers crash. Companies disappear. Your content deserves better. CLKT ensures your words, images, and ideas persist permanently on the decentralized web - no single point of failure, no corporate control, just permanent peace of mind.

**Perfect For:**

* Bloggers who want to preserve their writing legacy
* Photographers archiving their portfolios
* Journalists protecting their work from censorship
* Anyone who believes their content should last forever

**How It Works:**

1. Install CLKT on your WordPress site
2. Connect your Arweave wallet (Wander)
3. Click "Save Forever" on any post
4. Your content is permanently stored on Arweave
5. Get a permanent link that will work forever

Join the movement to make content truly permanent. Save forever, collect together.

**External Services:**

This plugin relies on the following third-party services. Post content, media, and metadata are not sent to storage or lookup services until you explicitly trigger an upload or lookup.

1. **Arweave Network** ([arweave.org](https://www.arweave.org)) - The core decentralized storage network. Your post content, media files, and metadata are transmitted to Arweave gateway nodes (arweave.net) for permanent storage when you click "Save Forever". Content becomes publicly accessible at arweave.net URLs. The plugin also uses a permanent viewer hosted on Arweave for displaying uploaded content. Small one-time AR token fees apply (paid to the Arweave network, not to CLKT).
   * [Terms of Service](https://www.arweave.org/terms-of-service)
   * [Privacy Policy](https://www.arweave.org/privacy)

2. **CoinGecko API** ([coingecko.com](https://www.coingecko.com)) - Used to fetch the current AR/USD exchange rate for cost estimation. Only a simple price query is sent; no user data is transmitted.
   * [Terms of Service](https://www.coingecko.com/en/terms)
   * [Privacy Policy](https://www.coingecko.com/en/privacy)

3. **Goldsky GraphQL** ([goldsky.com](https://goldsky.com)) - Used to search and verify Arweave transactions by ID. No user data is transmitted beyond transaction IDs.
   * [Terms of Service](https://goldsky.com/terms/terms-and-conditions)
   * [Privacy Policy](https://goldsky.com/privacy)

4. **AO Testnet** ([ao.arweave.dev](https://ao.arweave.dev)) - Part of the Arweave ecosystem, used for atomic asset registration when content is uploaded as an atomic asset.
   * [Terms of Service](https://www.arweave.org/terms-of-service)
   * [Privacy Policy](https://www.arweave.org/privacy)

== Installation ==

1. Upload the `clkt` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings > CLKT to configure your options
4. Start saving your content forever!

**Getting Started:**

* **Connect Wallet** - Use the Wander browser extension to connect your wallet and manage your permanent uploads directly.

== Frequently Asked Questions ==

= What is Arweave? =

Arweave is a decentralized storage network that ensures data permanence. Once uploaded, your content is replicated across hundreds of nodes worldwide and guaranteed to remain accessible forever.

= Do I need cryptocurrency? =

Yes, for direct uploads you'll need a small amount of AR tokens to cover network fees. These fees are one-time only for permanent storage.

= How much does it cost? =

Storage on Arweave requires a one-time fee (typically less than $0.01 per MB). No subscriptions, no recurring charges - pay once, store forever.

= Is my content public? =

Content saved to Arweave is publicly accessible by default. CLKT includes optional encryption with password protection for sensitive content. You can encrypt using:
* Your wallet key (most secure)
* Global password (shared across all posts)
* Per-post password (individual control)

Encrypted content requires the password to decrypt and view.

= Can I delete content once uploaded? =

Content on Arweave is permanent and cannot be deleted. This is a feature, not a bug - it ensures true content permanence.

= What happens to my media/images? =

CLKT automatically detects and saves all images and attachments associated with your posts. They're stored permanently alongside your content.

= What is Test Mode? =

Test mode simulates uploads without spending AR tokens or creating real Arweave transactions. Enable in Settings > CLKT > General Settings for development and testing. Test uploads are tagged with TEST_ prefix and hidden in production mode, so you can safely experiment without affecting your live content.

= Can I upload multiple posts at once? =

Yes! Use the Bulk Upload feature in Settings > CLKT > Bulk Upload to save your entire content library to Arweave. You can upload only new/changed content or force re-upload all posts. The preview shows exactly what will be uploaded and the total cost before you commit.

== Screenshots ==

1. CLKT settings page with easy-to-use tabbed interface
2. Post editor with auto-upload preview showing attachment detection and cost estimation
3. Posts list with Permaweb status column showing saved content
4. Bulk upload interface for saving multiple posts at once
5. Successful upload confirmation with permanent Arweave link

== External services ==

This plugin connects to the following third-party services during normal operation. Post content, media, and metadata are not sent until the user explicitly triggers an upload action.

= 1. Arweave Network (arweave.net) =

The core blockchain storage network. CLKT sends your post content, media files, and metadata to Arweave nodes for permanent decentralized storage. This is the primary function of the plugin - when you click "Save Forever", your content is transmitted to the Arweave network.

The plugin connects to the following Arweave gateway domains:

* arweave.net - For uploading content and retrieving stored content via transaction IDs
* 2deruxhrgmrp2browgc472rhocjc7xjbkrpac7bmg6psfq26ue7q.arweave.net - For viewing uploaded content with the permanent viewer

Data sent: Post content (title, body, excerpt), attached media files, post metadata, and wallet address. Content is sent when the user explicitly clicks "Save Forever" or uses bulk upload. Uploaded content becomes publicly accessible on the Arweave network.

* [Arweave Terms of Service](https://www.arweave.org/terms-of-service)
* [Arweave Privacy Policy](https://www.arweave.org/privacy)

= 2. CoinGecko API (api.coingecko.com) =

Used to fetch the current AR/USD exchange rate for cost estimation. Only a simple price query is sent - no user data is transmitted to CoinGecko.

* [CoinGecko Terms of Service](https://www.coingecko.com/en/terms)
* [CoinGecko Privacy Policy](https://www.coingecko.com/en/privacy)

= 3. Goldsky GraphQL (arweave-search.goldsky.com) =

Used to search and verify Arweave transactions. The plugin sends transaction IDs to Goldsky to look up upload status and confirm that content was stored successfully.

* [Goldsky Terms of Service](https://goldsky.com/terms/terms-and-conditions)
* [Goldsky Privacy Policy](https://goldsky.com/privacy)

= 4. AO Testnet (su-router.ao-testnet.xyz) =

Part of the Arweave ecosystem, used for atomic asset registration. When content is uploaded as an atomic asset, the plugin communicates with the AO network for registration.

* [AO / Arweave Terms of Service](https://www.arweave.org/terms-of-service)
* [AO / Arweave Privacy Policy](https://www.arweave.org/privacy)

== Changelog ==

= 1.0.19 =
* Fixed critical race condition in auto-upload system preventing re-uploads after editing published posts
* Modified save_version() to only clear pending flags if upload brought post up to date
* Added content hash checking to detect when post was edited after upload started
* All 16 Tier 1 tests passing

= 1.0.6 =
* Fixed critical bug where transaction IDs were saved as "[object Object]" in timeline
* Fixed verification logic to correctly show error status for invalid transaction IDs
* Fixed single-click upload - removed redundant check that required clicking twice
* Added comprehensive debug logging for troubleshooting upload issues

= 1.0.5 =
* Fixed force reupload mode - attachments now upload correctly when force reupload is enabled
* Fixed cost calculation consistency across all views (preview details and summary)
* Improved upload state display in force reupload mode (shows "Up to date" with reupload indicator)
* Fixed cost calculations to use configurable free dispatch limit instead of hardcoded values
* Enhanced upload mode handling and parameter passing throughout the upload chain

= 1.0.4 =
* Fixed bulk upload logic and improved test mode handling
* Improved upload state tracking to properly detect editor changes
* Fixed test mode upload visibility in post listings
* Enhanced permaweb integration and refactored upload flows

= 1.0.3 =
* Fixed attachment reuse logic in new_only mode
* Improved TEST_ transaction filtering in production mode
* Enhanced upload preview debugging and fixed display issues
* Added backward compatibility for content hash verification

= 1.0.2 =
* Fixed wallet connection refresh on page load
* Improved editor change detection and auto-upload behavior
* Enhanced ArConnect integration for paid uploads
* Fixed signing process for uploads larger than 100KB

= 1.0.1 =
* Added configurable max upload size setting
* Clarified free dispatch limit in settings
* Improved paid upload support for files >100KB
* Enhanced test mode banner persistence
* UI improvements and bug fixes

= 1.0.0 =
* Initial release
* One-click permanent storage for posts and pages
* Automatic attachment detection and upload
* Self-managed wallet integration (Wander)
* Bulk upload functionality
* Version history tracking
* Consolidated settings interface

== Upgrade Notice ==

= 1.0.19 =
Critical fix for auto-upload race condition. Re-uploads after editing published posts now work correctly. Highly recommended update.

= 1.0.6 =
Critical bug fixes for transaction ID saving and upload reliability. Highly recommended update for all users.

= 1.0.5 =
Important bug fixes for force reupload mode and cost calculation. Recommended update for all users.

= 1.0.0 =
First release of CLKT - Start saving your content forever!

== Additional Info ==

**Contributing**: Find us on GitHub at [github.com/divydovy/clkt-save-forever-collect-together](https://github.com/divydovy/clkt-save-forever-collect-together)

**Support**: Visit [getclkt.com](https://getclkt.com) for documentation and support

**Join the Community**: Follow us for updates and tips

Remember: Save Forever, Collect Together!
