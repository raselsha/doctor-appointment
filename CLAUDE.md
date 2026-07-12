# WC Service Booking Manager — CLAUDE.md

## WordPress Plugin Development Rules

### Project
Professional WordPress plugin. Always follow WordPress Coding Standards.

### Tech Stack
- PHP 8+
- WordPress
- JavaScript (ES6)
- jQuery
- HTML
- CSS
- Elementor

### Coding Rules
- Use OOP.
- Never edit vendor files.
- Escape all output.
- Sanitize all user input.
- Validate all data.
- Use WordPress hooks whenever possible.
- Use AJAX through admin-ajax.php.
- Keep functions small.
- Write reusable code.

### CSS Rules
- Use BEM naming.
- Avoid !important.
- Mobile first.
- Use CSS variables where appropriate.

### JavaScript Rules
- Use jQuery.
- No inline scripts.
- Use event delegation.
- Write modular code.

### UI
- WordPress admin style.
- Clean spacing.
- Rounded corners.
- Professional SaaS appearance.

### Before Writing Code
Always:
1. Analyze the task.
2. Create a plan.
3. Explain the approach.
4. Wait for approval.
5. Then write code.

Never modify unrelated files.
Never break backward compatibility.
Always explain why changes are made.

## Build/Test Commands
- No build step (vanilla PHP/JS/CSS)
- Test: `php -l` on PHP files to check syntax
- Lint JS: `npx eslint assets/*.js`
- Lint CSS: `npx stylelint assets/*.css`

## Code Style
- **PHP**: WordPress PHP Coding Standards (WPCS). Classes use `WCSBM\` namespace prefix. PSR-4 autoloading under `classes/`.
- **JS**: ES5 compatible (no transpiler). jQuery-based. Functions camelCased. Event handlers use `$(document).on(...)`.
- **CSS**: BEM-like naming `.wcsbm-*`. Mobile-first responsive at 768px breakpoint.
- **Naming**: Classes prefixed `WCSBM\Admin\`, `WCSBM\Frontend\`, `WCSBM\Product\`. Files match class names.

## Architecture
- **Entry**: `wc-service-booking.php` — defines constants, autoloader, boots all class singletons
- **Classes**: `classes/` — Admin (product type, settings, shortcodes, notifications, scheduler, staff), Frontend (booking modal, product template), Product (custom WC product type)
- **Assets**: `assets/` — `script.js` (modal/cart/frontend), `admin.js` (admin repeater), CSS files
- **Templates**: `templates/` — overridable WooCommerce template for single service booking product
- **DB**: Custom tables created by `WCSBM\Admin\SchedulerData::create_tables()` for scheduling/booking data
- **AJAX**: All AJAX handlers in `WCSBM\Frontend\Booking` and `WCSBM\Admin\SchedulerAjax`

## Key Patterns
- Admin notices via `WCSBM\Admin\Admin` singleton
- Telegram notifications in `WCSBM\Admin\Notification::send_telegram_notification()`
- Time slots generated 10:00–21:00 with configurable gap (default 15 min)
- Branches hardcoded in `WCSBM\Admin\Settings::get_branches()`
- Cart persistence: booking meta stored as order/session data via `WC()->session`
- Scheduler sync: `WCSBM\Admin\SchedulerData::sync_from_orders()` called on checkout and order save
