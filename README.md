# Buero Leads Plugin

A WordPress plugin that retrieves lead information directly from Notion and displays it via shortcodes. It also handles button redirections based on lead context.

## Features

- **Lead Information Display**: Use `[lead_info]` to display specific lead data fields from a Notion Database.
- **Dynamic Redirection**: Buttons can redirect users to specific URLs defined in attributes.
- **Notion Integration**: Connects directly to Notion API using an Internal Integration Token.
- **Admin Settings Panel**: Configure Notion Token and ID Property.

## Installation

1. Upload the plugin files to `/wp-content/plugins/buero-leads-plugin/` directory.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Configure the plugin settings in **Settings > Buero Leads**.
4. The plugin is ready to use!

## Configuration

### Admin Settings

Navigate to **Settings > Buero Leads** to configure:

- **Notion Integration Token**: Your Notion Internal Integration Token (starts with `secret_...`).
- **Notion ID Property Name**: The name of the property in your Notion Database that acts as the unique ID (default: "ID").

You can test the connection directly from the settings page.

## Usage

### Lead Information Shortcode

Display specific lead data fields using the `[lead_info]` shortcode:

```
[lead_info column="Company Name" notion_id="YOUR_DATABASE_ID" default="Default Company"]
```

- **column**: The exact name of the property in your Notion Database.
- **notion_id**: The ID of the Notion Database where the lead exists.
- **default**: (Optional) Text to display if the lead is not found or the property is empty.

### Buttons

To create a button that redirects users (e.g., for CV download), use standard HTML with specific attributes:

```html
<button
  class="bb-counter-button"
  id="cv_view"
  data-btn-target="https://example.com/cv.pdf"
>
  Download CV
</button>
```

- **class**: Must include `bb-counter-button`.
- **id**: Unique identifier for the button type (e.g., `cv_view`, `portfolio_view`).
- **data-btn-target**: The URL where the user should be redirected.

### How It Works

1. The plugin extracts the `id` parameter from the current page's URL query string (e.g., `?id=lead-123`).
2. It queries the specified Notion Database for a page where the configured **ID Property** matches this value.
3. If found, it retrieves the value of the requested **column** property.
4. Shortcodes display this value on the page.

## Technical Details

### File Structure

```
buero-leads-plugin/
├── buero-leads-plugin.php          # Main plugin file
├── includes/
│   ├── class-lead-api-handler.php  # API communication handler
│   └── class-lead-api-notion-client.php # Notion API Client
├── assets/
│   ├── buero-leads.css            # Button styling
│   └── buero-leads.js             # JavaScript functionality
└── README.md                      # This file
```

### Dependencies

- **WordPress**: 5.0 or higher
- **PHP**: 7.4 or higher
- **jQuery**: For button interactions

## Troubleshooting

### Common Issues

1. **No data displayed**:
   - Check that the `id` parameter is present in the URL.
   - Verify the Notion Token is correct and has access to the database.
   - Ensure the `column` name matches exactly with the Notion property (case-sensitive).
   - Ensure the `notion_id` in the shortcode is correct.

2. **Connection Failed**:
   - Verify your Notion Token.
   - Ensure your server can make outbound requests to `api.notion.com`.

## Changelog

### Version 1.0.0

- Initial release with Notion integration.
