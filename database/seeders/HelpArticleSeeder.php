<?php

namespace Database\Seeders;

use App\Models\HelpArticle;
use Illuminate\Database\Seeder;

class HelpArticleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $articles = $this->getArticles();

        foreach ($articles as $index => $article) {
            HelpArticle::updateOrCreate(
                ['slug' => $article['slug']],
                array_merge($article, ['sort_order' => $index])
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getArticles(): array
    {
        return [
            // ── Getting Started ──────────────────────────────────────
            [
                'category' => 'Getting Started',
                'title' => 'Welcome to Shopmata',
                'slug' => 'welcome-to-shopmata',
                'excerpt' => 'An overview of the Shopmata platform and what you can do with it.',
                'content' => '<h2>What is Shopmata?</h2><p>Shopmata is an all-in-one inventory and sales management platform built for retail stores. It helps you manage your products, process buys from customers, fulfill orders across multiple sales channels, and track your business performance with detailed reports.</p><h2>Key Features</h2><ul><li><strong>Product Management</strong> — Create, organize, and track your product catalog with categories, tags, and custom attributes.</li><li><strong>Multi-Channel Sales</strong> — Sell on Shopify, eBay, Amazon, WooCommerce, and Walmart from a single dashboard.</li><li><strong>Buy Processing</strong> — Purchase items from walk-in customers with structured buy transactions.</li><li><strong>Inventory Tracking</strong> — Real-time stock levels across all warehouses and sales channels.</li><li><strong>Reports & Analytics</strong> — Daily, monthly, and yearly reports for buys, sales, inventory, and leads.</li><li><strong>AI Assistant</strong> — Chat with the built-in assistant to get quick answers about your business data.</li></ul><h2>Getting Around</h2><p>Use the sidebar on the left to navigate between sections. The dashboard gives you a quick overview of your store\'s performance. Settings are in the bottom-left corner.</p>',
                'is_published' => true,
            ],
            [
                'category' => 'Getting Started',
                'title' => 'Setting Up Your Store',
                'slug' => 'setting-up-your-store',
                'excerpt' => 'How to configure your store name, logo, and basic settings.',
                'content' => '<h2>Store Settings</h2><p>Navigate to <strong>Settings → Store</strong> to configure your store\'s basic information.</p><h3>What You Can Set Up</h3><ul><li><strong>Store Name</strong> — Your business name that appears throughout the app and on invoices.</li><li><strong>Logo</strong> — Upload your store logo for branding on invoices and receipts.</li><li><strong>Currency</strong> — Set your default currency for pricing and reports.</li><li><strong>Timezone</strong> — Ensure reports and timestamps use your local time.</li></ul><h3>Next Steps</h3><p>After setting up your store, you should:</p><ol><li>Add your team members under <strong>Settings → Team</strong></li><li>Configure your sales channels under <strong>Settings → Sales Channels</strong></li><li>Set up your warehouses under <strong>Settings → Warehouses</strong></li><li>Start adding products to your catalog</li></ol>',
                'is_published' => true,
            ],
            [
                'category' => 'Getting Started',
                'title' => 'Understanding the Dashboard',
                'slug' => 'understanding-the-dashboard',
                'excerpt' => 'Learn what each section of the dashboard shows you.',
                'content' => '<h2>Dashboard Overview</h2><p>The dashboard is your home base. It shows a snapshot of your store\'s performance at a glance.</p><h3>Key Metrics</h3><ul><li><strong>Today\'s Sales</strong> — Revenue from orders completed today.</li><li><strong>Today\'s Buys</strong> — Total spent on customer buys today.</li><li><strong>Pending Orders</strong> — Orders that need to be fulfilled.</li><li><strong>Low Stock Alerts</strong> — Products that are running low on inventory.</li></ul><h3>Using the AI Assistant</h3><p>Click the chat icon in the bottom-right corner to ask the AI assistant questions about your business. For example, you can ask "How are sales doing this week?" or "What products are low on stock?"</p>',
                'is_published' => true,
            ],

            // ── Products ─────────────────────────────────────────────
            [
                'category' => 'Products',
                'title' => 'Creating a New Product',
                'slug' => 'creating-a-new-product',
                'excerpt' => 'Step-by-step guide to adding a product to your catalog.',
                'content' => '<h2>How to Create a Product</h2><ol><li>Navigate to <strong>Products</strong> in the sidebar.</li><li>Click the <strong>Create Product</strong> button in the top right.</li><li>Fill in the product details:<ul><li><strong>Title</strong> — The name of the product.</li><li><strong>Category</strong> — Select from your product categories (this can auto-generate SKUs).</li><li><strong>SKU</strong> — A unique identifier. You can auto-generate one based on category settings.</li><li><strong>Price</strong> — The selling price. You can set different prices per sales channel later.</li><li><strong>Cost</strong> — What you paid for the item (used in profit calculations).</li><li><strong>Description</strong> — A rich text description for your listings.</li></ul></li><li>Add images by dragging and dropping or clicking the upload area.</li><li>Click <strong>Save</strong> to create the product.</li></ol><h3>Tips</h3><ul><li>You can use the <strong>AI Generate</strong> button to auto-generate titles and descriptions from your product images.</li><li>Products automatically create listings for all your active sales channels.</li><li>Use <strong>Tags</strong> to organize products for easy filtering.</li></ul>',
                'is_published' => true,
            ],
            [
                'category' => 'Products',
                'title' => 'Editing Products',
                'slug' => 'editing-products',
                'excerpt' => 'How to update product details, prices, and images.',
                'content' => '<h2>Editing a Product</h2><ol><li>Go to <strong>Products</strong> and find the product you want to edit.</li><li>Click on the product to view its details, then click <strong>Edit</strong>.</li><li>Make your changes to any field — title, price, description, images, etc.</li><li>Click <strong>Save</strong> to apply your changes.</li></ol><h3>Bulk Editing</h3><p>To edit multiple products at once:</p><ol><li>On the Products list page, select multiple products using the checkboxes.</li><li>Use the <strong>Bulk Actions</strong> menu to update fields like price, category, or tags across all selected products.</li></ol><h3>Inline Editing</h3><p>For quick changes, you can edit certain fields directly from the product list without opening the full edit page. Click on a field value to edit it in place.</p>',
                'is_published' => true,
            ],
            [
                'category' => 'Products',
                'title' => 'Managing Product Categories',
                'slug' => 'managing-product-categories',
                'excerpt' => 'How to create and organize product categories for your catalog.',
                'content' => '<h2>Product Categories</h2><p>Categories help you organize your products and can be used to auto-generate SKUs.</p><h3>Creating a Category</h3><ol><li>Navigate to <strong>Categories</strong> in the sidebar.</li><li>Click <strong>Add Category</strong>.</li><li>Enter the category name and optional parent category for nesting.</li><li>Configure SKU prefix if you want auto-generated SKUs for products in this category.</li></ol><h3>Category Hierarchy</h3><p>Categories support nesting — you can create subcategories by selecting a parent category. For example: Jewelry → Rings → Engagement Rings.</p>',
                'is_published' => true,
            ],
            [
                'category' => 'Products',
                'title' => 'Printing Labels and Barcodes',
                'slug' => 'printing-labels-and-barcodes',
                'excerpt' => 'How to print product labels with barcodes for your inventory.',
                'content' => '<h2>Printing Labels</h2><p>Shopmata supports printing barcode labels for your products.</p><h3>Printing from a Product</h3><ol><li>Open a product\'s detail page.</li><li>Click the <strong>Print Barcode</strong> button.</li><li>Select your label template and printer.</li><li>Print the label.</li></ol><h3>Label Templates</h3><p>You can create custom label templates under <strong>Labels</strong> in the sidebar. Templates define the size, layout, and information shown on each label (SKU, price, barcode, etc.).</p><h3>Printer Setup</h3><p>Configure your label printers under <strong>Settings → Printers</strong>. Shopmata supports network printers and direct USB connections.</p>',
                'is_published' => true,
            ],

            // ── Inventory ────────────────────────────────────────────
            [
                'category' => 'Inventory',
                'title' => 'Understanding Inventory Levels',
                'slug' => 'understanding-inventory-levels',
                'excerpt' => 'How inventory tracking works across warehouses and channels.',
                'content' => '<h2>Inventory Tracking</h2><p>Shopmata tracks inventory levels for each product across your warehouses and sales channels.</p><h3>How It Works</h3><ul><li><strong>On Hand</strong> — The total quantity you physically have in stock.</li><li><strong>Committed</strong> — Quantity reserved for pending orders.</li><li><strong>Available</strong> — On Hand minus Committed — what you can still sell.</li></ul><h3>Warehouse Inventory</h3><p>If you have multiple warehouses, each warehouse tracks its own stock levels. The product page shows a breakdown by warehouse.</p><h3>Channel Sync</h3><p>When you sell on multiple channels (Shopify, eBay, etc.), inventory is automatically synced. When an item sells on one channel, the available quantity updates across all channels.</p>',
                'is_published' => true,
            ],
            [
                'category' => 'Inventory',
                'title' => 'Adjusting Inventory',
                'slug' => 'adjusting-inventory',
                'excerpt' => 'How to manually adjust stock levels and transfer between warehouses.',
                'content' => '<h2>Inventory Adjustments</h2><p>Sometimes you need to manually adjust inventory — for example, after a physical count or to correct errors.</p><h3>Making an Adjustment</h3><ol><li>Navigate to <strong>Inventory</strong> in the sidebar.</li><li>Find the product and click on it.</li><li>Use the <strong>Adjust</strong> button to increase or decrease the quantity.</li><li>Enter a reason for the adjustment (for audit trail purposes).</li></ol><h3>Transferring Between Warehouses</h3><p>To move stock from one warehouse to another, create an inventory transfer. This reduces stock at the source warehouse and increases it at the destination.</p>',
                'is_published' => true,
            ],
            [
                'category' => 'Inventory',
                'title' => 'Inventory Allocation',
                'slug' => 'inventory-allocation',
                'excerpt' => 'How to allocate inventory to specific sales channels or orders.',
                'content' => '<h2>Inventory Allocation</h2><p>Allocation lets you reserve inventory for specific purposes — such as a sales channel, a pending order, or a memo.</p><h3>Automatic Allocation</h3><p>When an order is placed, inventory is automatically allocated (committed) to that order. This prevents overselling across channels.</p><h3>Manual Allocation</h3><p>You can also manually allocate products to specific channels or hold them for a customer. Go to the product\'s inventory section and use the allocation controls.</p>',
                'is_published' => true,
            ],

            // ── Buys ─────────────────────────────────────────────────
            [
                'category' => 'Buys',
                'title' => 'Creating an In-Store Buy',
                'slug' => 'creating-an-in-store-buy',
                'excerpt' => 'How to process a purchase from a walk-in customer.',
                'content' => '<h2>Processing a Buy</h2><p>A "buy" is when you purchase items from a customer (as opposed to selling to them). This is common in jewelry, consignment, and secondhand retail.</p><h3>Steps to Create a Buy</h3><ol><li>Navigate to <strong>Buys</strong> in the sidebar.</li><li>Click <strong>New Buy</strong>.</li><li>Select or create the customer you\'re buying from.</li><li>Add items to the buy:<ul><li>Search for existing products, or</li><li>Create new products on the fly by entering details.</li></ul></li><li>Set the buy price for each item (what you\'re paying the customer).</li><li>Review the total and click <strong>Complete Buy</strong>.</li></ol><h3>After the Buy</h3><p>Once a buy is completed:</p><ul><li>The items are added to your inventory automatically.</li><li>A buy transaction record is created for your records.</li><li>You can view the buy under the customer\'s profile.</li></ul>',
                'is_published' => true,
            ],
            [
                'category' => 'Buys',
                'title' => 'Buy Reports',
                'slug' => 'buy-reports',
                'excerpt' => 'How to view and analyze your buying activity.',
                'content' => '<h2>Buy Reports</h2><p>Track your buying activity with the various buy reports available under <strong>Reports</strong>.</p><h3>Available Reports</h3><ul><li><strong>Buys (Daily)</strong> — Day-by-day breakdown of buy transactions.</li><li><strong>Buys (Month over Month)</strong> — Compare buying activity across months.</li><li><strong>Buys (Month to Date)</strong> — Current month\'s buying summary.</li><li><strong>Buys (Year over Year)</strong> — Annual buying trends.</li></ul><h3>Reading the Reports</h3><p>Each report shows total amount spent, number of transactions, and average buy value. Use the date filters to narrow down the time period you want to analyze.</p>',
                'is_published' => true,
            ],

            // ── Orders ───────────────────────────────────────────────
            [
                'category' => 'Orders',
                'title' => 'Managing Orders',
                'slug' => 'managing-orders',
                'excerpt' => 'How to view, fulfill, and manage customer orders.',
                'content' => '<h2>Order Management</h2><p>Orders come in from your various sales channels (Shopify, eBay, etc.) and appear in the <strong>Orders</strong> section.</p><h3>Order Statuses</h3><ul><li><strong>Pending</strong> — New order, awaiting processing.</li><li><strong>Processing</strong> — Order is being prepared for shipment.</li><li><strong>Shipped</strong> — Order has been shipped to the customer.</li><li><strong>Delivered</strong> — Order has been received by the customer.</li><li><strong>Cancelled</strong> — Order was cancelled.</li></ul><h3>Fulfilling an Order</h3><ol><li>Go to <strong>Orders</strong> and click on the order.</li><li>Review the items and shipping details.</li><li>Pack the items and generate a shipping label if needed.</li><li>Mark the order as <strong>Shipped</strong> and enter the tracking number.</li></ol>',
                'is_published' => true,
            ],
            [
                'category' => 'Orders',
                'title' => 'Creating Invoices',
                'slug' => 'creating-invoices',
                'excerpt' => 'How to generate and send invoices to customers.',
                'content' => '<h2>Invoices</h2><p>Invoices can be created for orders or as standalone documents for custom transactions.</p><h3>Creating an Invoice</h3><ol><li>Navigate to <strong>Invoices</strong> in the sidebar.</li><li>Click <strong>Create Invoice</strong>.</li><li>Select the customer and add line items.</li><li>Set payment terms and due date.</li><li>Save and send the invoice to the customer via email.</li></ol><h3>Invoice from an Order</h3><p>You can also generate an invoice directly from an order by clicking the <strong>Generate Invoice</strong> button on the order detail page.</p>',
                'is_published' => true,
            ],
            [
                'category' => 'Orders',
                'title' => 'Processing Layaways',
                'slug' => 'processing-layaways',
                'excerpt' => 'How to set up and manage layaway payments for customers.',
                'content' => '<h2>Layaways</h2><p>Layaway allows customers to pay for items over time while you hold the product for them.</p><h3>Creating a Layaway</h3><ol><li>Navigate to <strong>Layaways</strong> in the sidebar.</li><li>Click <strong>New Layaway</strong>.</li><li>Select the customer and add the products.</li><li>Set the payment schedule (down payment, installment amounts, due dates).</li><li>Confirm the layaway.</li></ol><h3>Managing Payments</h3><p>As the customer makes payments, record each payment on the layaway. Once fully paid, release the items to the customer.</p>',
                'is_published' => true,
            ],

            // ── Customers ────────────────────────────────────────────
            [
                'category' => 'Customers',
                'title' => 'Managing Customers',
                'slug' => 'managing-customers',
                'excerpt' => 'How to add, edit, and track customer information.',
                'content' => '<h2>Customer Management</h2><p>Keep track of all your customers in one place.</p><h3>Adding a Customer</h3><ol><li>Go to <strong>Customers</strong> in the sidebar.</li><li>Click <strong>Add Customer</strong>.</li><li>Enter their name, email, phone, and any notes.</li><li>Save the customer profile.</li></ol><h3>Customer Profiles</h3><p>Each customer profile shows:</p><ul><li>Contact information</li><li>Order history</li><li>Buy history (items purchased from them)</li><li>Notes and communication log</li></ul><h3>Lead Sources</h3><p>Track where your customers come from by assigning lead sources. Configure lead sources under <strong>Settings → Lead Sources</strong>.</p>',
                'is_published' => true,
            ],
            [
                'category' => 'Customers',
                'title' => 'SMS Messaging',
                'slug' => 'sms-messaging',
                'excerpt' => 'How to send and manage SMS messages to customers.',
                'content' => '<h2>SMS Messaging</h2><p>Communicate with customers directly via SMS from within Shopmata.</p><h3>Sending a Message</h3><ol><li>Navigate to <strong>SMS Messages</strong> in the sidebar.</li><li>Click <strong>New Message</strong>.</li><li>Select the customer or enter a phone number.</li><li>Type your message and send.</li></ol><h3>Conversation View</h3><p>Messages are organized in a conversation view, so you can see the full history of your communications with each customer.</p>',
                'is_published' => true,
            ],

            // ── Reports ──────────────────────────────────────────────
            [
                'category' => 'Reports',
                'title' => 'Sales Reports',
                'slug' => 'sales-reports',
                'excerpt' => 'How to access and read your sales reports.',
                'content' => '<h2>Sales Reports</h2><p>Sales reports help you understand your revenue and selling patterns.</p><h3>Available Sales Reports</h3><ul><li><strong>Daily Orders</strong> — See each day\'s orders and revenue.</li><li><strong>Daily Items</strong> — See individual items sold per day.</li><li><strong>Month over Month</strong> — Compare sales performance across months.</li><li><strong>Month to Date</strong> — Current month\'s sales summary.</li></ul><h3>Using Filters</h3><p>Use the date range picker to focus on specific time periods. You can also filter by sales channel, product category, or customer.</p><h3>Exporting</h3><p>Click the <strong>Export</strong> button to download your report as a CSV file for use in spreadsheets.</p>',
                'is_published' => true,
            ],
            [
                'category' => 'Reports',
                'title' => 'Inventory Report',
                'slug' => 'inventory-report',
                'excerpt' => 'How to view and analyze your current inventory levels.',
                'content' => '<h2>Inventory Report</h2><p>The inventory report gives you a comprehensive view of your current stock.</p><h3>Accessing the Report</h3><ol><li>Go to <strong>Reports → Inventory Report</strong> in the sidebar.</li><li>The report shows all products with their current stock levels, values, and statuses.</li></ol><h3>What the Report Shows</h3><ul><li><strong>Quantity on Hand</strong> — Total stock across all warehouses.</li><li><strong>Total Value</strong> — The total cost value of your inventory.</li><li><strong>Retail Value</strong> — The total selling value at current prices.</li><li><strong>Age</strong> — How long items have been in stock.</li></ul><h3>Filtering</h3><p>Filter by category, warehouse, or stock level to focus on specific segments of your inventory.</p>',
                'is_published' => true,
            ],
            [
                'category' => 'Reports',
                'title' => 'Leads Funnel Report',
                'slug' => 'leads-funnel-report',
                'excerpt' => 'Track your leads pipeline from inquiry to sale.',
                'content' => '<h2>Leads Funnel Report</h2><p>The leads funnel shows how your leads progress through your sales pipeline.</p><h3>Reading the Funnel</h3><p>The report visualizes how leads flow from initial inquiry through to completed sales. Each stage shows the number of leads and conversion rate to the next stage.</p><h3>Using the Data</h3><p>Use this report to identify bottlenecks in your sales process. If many leads drop off at a particular stage, that\'s where you should focus your improvement efforts.</p>',
                'is_published' => true,
            ],

            // ── Sales Channels ───────────────────────────────────────
            [
                'category' => 'Sales Channels',
                'title' => 'Connecting Sales Channels',
                'slug' => 'connecting-sales-channels',
                'excerpt' => 'How to connect Shopify, eBay, Amazon, and other marketplaces.',
                'content' => '<h2>Sales Channels</h2><p>Shopmata supports selling on multiple platforms simultaneously. Connect your accounts to sync products and orders.</p><h3>Supported Channels</h3><ul><li><strong>Shopify</strong> — Full integration with products, orders, and inventory sync.</li><li><strong>eBay</strong> — List products and manage eBay orders.</li><li><strong>Amazon</strong> — Sync products and fulfill Amazon orders.</li><li><strong>WooCommerce</strong> — Connect your WooCommerce store.</li><li><strong>Walmart</strong> — List and manage Walmart marketplace products.</li></ul><h3>Connecting a Channel</h3><ol><li>Go to <strong>Settings → Marketplaces</strong>.</li><li>Click <strong>Add Marketplace</strong>.</li><li>Select the platform and follow the authorization steps.</li><li>Once connected, configure your sync settings.</li></ol>',
                'is_published' => true,
            ],
            [
                'category' => 'Sales Channels',
                'title' => 'Managing Listings',
                'slug' => 'managing-listings',
                'excerpt' => 'How to publish and manage product listings across channels.',
                'content' => '<h2>Listings</h2><p>When you create a product, Shopmata automatically creates draft listings for each of your active sales channels.</p><h3>Publishing a Listing</h3><ol><li>Open a product and go to the <strong>Listings</strong> tab.</li><li>For each channel, review the listing details (title, description, price, category).</li><li>You can customize the listing per channel — for example, different titles or prices for eBay vs Shopify.</li><li>Click <strong>Publish</strong> to push the listing live on that channel.</li></ol><h3>Platform-Specific Details</h3><p>Each platform has its own requirements (eBay item specifics, Amazon categories, etc.). Shopmata helps you fill in platform-specific fields through the listing editor.</p>',
                'is_published' => true,
            ],

            // ── Settings ─────────────────────────────────────────────
            [
                'category' => 'Settings',
                'title' => 'Managing Your Team',
                'slug' => 'managing-your-team',
                'excerpt' => 'How to invite team members and manage roles and permissions.',
                'content' => '<h2>Team Management</h2><p>Invite your staff to Shopmata and control what they can access.</p><h3>Inviting a Team Member</h3><ol><li>Go to <strong>Settings → Team</strong>.</li><li>Click <strong>Invite Member</strong>.</li><li>Enter their email address and select a role.</li><li>They\'ll receive an email invitation to join your store.</li></ol><h3>Roles & Permissions</h3><p>Roles define what each team member can do. Go to <strong>Settings → Roles</strong> to create custom roles with specific permissions like:</p><ul><li>View/create/edit/delete products</li><li>Process buys</li><li>Manage orders</li><li>Access reports</li><li>Manage settings</li></ul>',
                'is_published' => true,
            ],
            [
                'category' => 'Settings',
                'title' => 'Configuring Notifications',
                'slug' => 'configuring-notifications',
                'excerpt' => 'Set up email notifications and scheduled reports.',
                'content' => '<h2>Notification Settings</h2><p>Configure how and when Shopmata notifies you about important events.</p><h3>Email Notifications</h3><p>Go to <strong>Settings → Notifications</strong> to manage your notification preferences. You can enable/disable notifications for:</p><ul><li>New orders</li><li>Low stock alerts</li><li>Buy completions</li><li>Team activity</li></ul><h3>Scheduled Reports</h3><p>Set up automated reports that are emailed to you on a schedule:</p><ol><li>Go to <strong>Settings → Notifications → Scheduled Reports</strong>.</li><li>Click <strong>Create Schedule</strong>.</li><li>Select the report type, frequency (daily, weekly, monthly), and recipients.</li></ol>',
                'is_published' => true,
            ],
            [
                'category' => 'Settings',
                'title' => 'Knowledge Base for AI Assistant',
                'slug' => 'knowledge-base-for-ai-assistant',
                'excerpt' => 'How to configure what your storefront AI assistant knows.',
                'content' => '<h2>Knowledge Base</h2><p>The Knowledge Base powers your storefront AI assistant — the chatbot that helps customers on your website.</p><h3>Adding Entries</h3><ol><li>Go to <strong>Settings → Knowledge Base</strong>.</li><li>Click <strong>Add Entry</strong>.</li><li>Select a category (FAQ, Return Policy, Shipping Info, etc.).</li><li>Enter a title and the content you want the AI to know.</li></ol><h3>How It Works</h3><p>When a customer asks a question through the storefront chat widget, the AI assistant uses your knowledge base entries to provide accurate answers specific to your store.</p><h3>Best Practices</h3><ul><li>Add your return and exchange policy.</li><li>Include shipping information and timeframes.</li><li>Add FAQs about your products and services.</li><li>Keep entries clear and concise for the best AI responses.</li></ul>',
                'is_published' => true,
            ],
            [
                'category' => 'Settings',
                'title' => 'Setting Up Warehouses',
                'slug' => 'setting-up-warehouses',
                'excerpt' => 'How to configure your warehouse locations for inventory tracking.',
                'content' => '<h2>Warehouses</h2><p>Warehouses represent your physical locations where you store inventory.</p><h3>Adding a Warehouse</h3><ol><li>Go to <strong>Settings → Warehouses</strong>.</li><li>Click <strong>Add Warehouse</strong>.</li><li>Enter the warehouse name and address.</li><li>Set it as default if it\'s your primary location.</li></ol><h3>Multiple Warehouses</h3><p>If you have multiple locations, add each one. Products can have different stock levels per warehouse, and you can transfer inventory between them.</p>',
                'is_published' => true,
            ],
        ];
    }
}
