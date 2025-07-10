# Kiss-woo-tiered-discounts
KISS WooCommerce Tiered Discounts - Alpha - Use at your own risk.
Test this plugin thoroughly before using it on a Live/Prodution Site.
Neither a warranty or any type of support is offered.

Add limited‑allocation **tiered discounts** to any WooCommerce product  
(e.g. first 10 units –30 %, next 10 –20 %, last 10 –10 %), with automatic
price adjustment, oversell protection, admin reporting and optional e‑mail
alerts.

---

## Table of Contents
- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Admin Settings](#admin-settings)
- [Shortcodes & Widgets](#shortcodes--widgets)
- [FAQ](#faq)
- [Contributing](#contributing)
- [Roadmap](#roadmap)
- [Changelog](#changelog)
- [License](#license)

---

## Features
|                         |                                             |
|-------------------------|---------------------------------------------|
| **Fixed inventory pool**| Allocate a total number of promotional units per product. |
| **Tiered pricing**      | Define unlimited tiers as **qty \| discount%** (e.g. `10\|30`). |
| **Automatic switching** | When one tier sells out, the next discount applies instantly. |
| **Race‑condition safe** | Atomic stock locking at order‑creation prevents overselling. |
| **Variable products**   | Works on parent product; all variations inherit the tiers. |
| **Front‑end notice**    | Shows “xx % off for the next yy units” on the product page. |
| **Cart/Checkout sync**  | Prices recalc in cart and order totals; blended discount when a basket spans tiers. |
| **Admin dashboard**     | Global overview of units sold / remaining for every promo. |
| **E‑mail alerts**       | Optional notifications when a tier sells out. |
| **Shortcode**           | `[wc_tid_status product_id="123"]` to display live progress anywhere. |

---

## Requirements
- **WordPress 6.0+**
- **WooCommerce 8.0+**
- PHP 7.4 or newer

---

## Installation
1. **Download or clone** this repository into  
   `wp-content/plugins/wc-tiered-inventory-discount/`
2. Ensure the main file is named  
   `wc-tiered-inventory-discount.php`
3. Go to **WP Admin → Plugins** and click **Activate**.

> **Tip:** Always test on a staging site before going live.

---

## Quick Start
1. **Edit a product** (simple or variable).
2. In the **“Tiered Discount Promotion”** metabox (sidebar)  
   - Tick **Enable promotion**.  
   - Enter **Total promotional units** (e.g. `30`).  
   - Enter tiers, one per line, in `qty|discount%` format:
     \`\`\`
     10|30
     10|20
     10|10
     \`\`\`
   - **Update** the product.

That’s it! The product page now advertises the current tier and WooCommerce
will automatically apply the correct discount at checkout.

---

## Admin Settings
| Location | Function |
|----------|----------|
| **Product metabox** (Products → Edit) | Enable/disable promotion, set total units, define tiers. |
| **WooCommerce → Tiered Discounts** | Read‑only dashboard showing each product’s total, sold and remaining units. |

---

## Shortcodes & Widgets
\`\`\`text
[wc_tid_status product_id="123"]
\`\`\`
Displays a live status panel for the selected product:

\`\`\`
Awesome T‑Shirt
14 of 30 promotional units remain.
• 30 % – 10 / 10 sold
• 20 % –  6 / 10 sold
• 10 % –  0 / 10 sold
\`\`\`

Add to a **Shortcode** block, sidebar widget, footer, etc.

---

## FAQ

<details>
<summary><strong>Does it support per‑variation tiers?</strong></summary>
Not yet; tiers are stored on the parent product. The discount will apply to
any variation purchased. Per‑variation limits are on the roadmap.
</details>

<details>
<summary><strong>What happens when all promo units sell out?</strong></summary>
The product price reverts to its regular price automatically.
</details>

<details>
<summary><strong>Can customers combine coupon codes?</strong></summary>
Yes—discounts are baked into the product price. Coupons stack on top
according to WooCommerce’s standard rules.
</details>

<details>
<summary><strong>How is overselling prevented?</strong></summary>
When an order is created the plugin atomically increments a “sold” counter,
respecting WooCommerce’s *hold stock* setting to avoid race conditions under
heavy load.
</details>

---

## Contributing
Pull requests and issues are welcome! Please follow the WordPress coding
standards (\`phpcs --standard=WordPress\`) and include unit tests where
possible.

---

## Roadmap
- Per‑variation tier limits
- Gutenberg block for status display
- REST API endpoint for promotion stats
- Scheduled start/end dates for promotions

---

## Changelog
| Version | Date | Notes |
|---------|------|-------|
| **1.0.0** | 2025‑07‑10 | Initial public release. |

---

## License
Distributed under the **GNU GPL v2** – see \`LICENSE\` for details.
 
KISS WooCommerce Tiered Discounts - Alpha - Use at your own risk. 
Test this plugin thoroughly before using it on a Live/Prodution Site.  
Neither a warranty or any type of support is offered.  
---

> Made with ❤️ for WooCommerce store owners who love creative promotions.
