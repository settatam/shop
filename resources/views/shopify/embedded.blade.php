<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="shopify-api-key" content="{{ $shopifyApiKey }}">
    <title>Shopmata AI Assistant</title>
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f6f6f7; color: #202223; padding: 20px; }
        .card { background: #fff; border: 1px solid #e1e3e5; border-radius: 8px; padding: 20px; margin-bottom: 16px; }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
        .card-title { font-size: 16px; font-weight: 600; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 12px; font-weight: 500; }
        .badge-active { background: #aee9d1; color: #0d5a38; }
        .badge-inactive { background: #fed3d1; color: #8c1614; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 14px; font-weight: 500; margin-bottom: 4px; color: #6d7175; }
        .form-group input, .form-group textarea { width: 100%; padding: 8px 12px; border: 1px solid #c9cccf; border-radius: 4px; font-size: 14px; }
        .form-group input:focus, .form-group textarea:focus { outline: none; border-color: #5c6ac4; box-shadow: 0 0 0 1px #5c6ac4; }
        .form-group textarea { resize: vertical; min-height: 60px; }
        .color-input-wrapper { display: flex; align-items: center; gap: 8px; }
        .color-input-wrapper input[type="color"] { width: 40px; height: 36px; padding: 2px; border: 1px solid #c9cccf; border-radius: 4px; cursor: pointer; }
        .color-input-wrapper input[type="text"] { flex: 1; }
        .btn { display: inline-flex; align-items: center; padding: 8px 16px; border: none; border-radius: 4px; font-size: 14px; font-weight: 500; cursor: pointer; }
        .btn-primary { background: #008060; color: #fff; }
        .btn-primary:hover { background: #006e52; }
        .btn-primary:disabled { background: #b5e6d8; cursor: not-allowed; }
        .toast { position: fixed; bottom: 20px; right: 20px; background: #202223; color: #fff; padding: 12px 20px; border-radius: 8px; font-size: 14px; opacity: 0; transition: opacity 0.3s; pointer-events: none; }
        .toast.show { opacity: 1; }
        .skeleton { background: linear-gradient(90deg, #e1e3e5 25%, #f0f1f2 50%, #e1e3e5 75%); background-size: 200% 100%; animation: shimmer 1.5s infinite; border-radius: 4px; height: 36px; }
        @keyframes shimmer { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }
        .skeleton-text { height: 14px; width: 60%; margin-bottom: 8px; }
    </style>
</head>
<body>
    <div id="app">
        <div class="card">
            <div class="card-header">
                <span class="card-title">Connection</span>
                <span id="status-badge" class="badge">
                    <span class="skeleton skeleton-text" style="width:60px;height:14px;display:inline-block;"></span>
                </span>
            </div>
            <p id="shop-domain" style="font-size:14px;color:#6d7175;">Loading...</p>
        </div>

        <div class="card">
            <div class="card-header">
                <span class="card-title">Widget Settings</span>
            </div>

            <form id="settings-form">
                <div class="form-group">
                    <label for="assistant_name">Assistant Name</label>
                    <div id="skeleton-name" class="skeleton"></div>
                    <input type="text" id="assistant_name" name="assistant_name" placeholder="AI Assistant" style="display:none;">
                </div>

                <div class="form-group">
                    <label for="welcome_message">Welcome Message</label>
                    <div id="skeleton-message" class="skeleton" style="height:60px;"></div>
                    <textarea id="welcome_message" name="welcome_message" placeholder="Hi! How can I help you today?" style="display:none;"></textarea>
                </div>

                <div class="form-group">
                    <label for="accent_color">Accent Color</label>
                    <div id="skeleton-color" class="skeleton" style="width:200px;"></div>
                    <div class="color-input-wrapper" id="color-wrapper" style="display:none;">
                        <input type="color" id="accent_color_picker" value="#008060">
                        <input type="text" id="accent_color" name="accent_color" placeholder="#008060" maxlength="7">
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" id="save-btn" disabled>Save Settings</button>
            </form>
        </div>
    </div>

    <div class="toast" id="toast"></div>

    <script>
        const API_BASE = '/shopify/embedded/api';

        function showToast(message, duration = 3000) {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.classList.add('show');
            setTimeout(() => toast.classList.remove('show'), duration);
        }

        async function getSessionToken() {
            try {
                return await shopify.idToken();
            } catch {
                showToast('Failed to get session token');
                return null;
            }
        }

        async function apiFetch(path, options = {}) {
            const token = await getSessionToken();
            if (!token) return null;

            const res = await fetch(API_BASE + path, {
                ...options,
                headers: {
                    'Authorization': 'Bearer ' + token,
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    ...(options.headers || {}),
                },
            });

            if (!res.ok) {
                const err = await res.json().catch(() => ({}));
                throw new Error(err.error || 'Request failed');
            }

            return res.json();
        }

        async function loadSettings() {
            try {
                const data = await apiFetch('/settings');
                if (!data) return;

                // Populate connection info
                document.getElementById('shop-domain').textContent = data.shop_domain || 'Unknown';
                const badge = document.getElementById('status-badge');
                const isActive = data.status === 'active';
                badge.textContent = isActive ? 'Active' : 'Inactive';
                badge.className = 'badge ' + (isActive ? 'badge-active' : 'badge-inactive');

                // Populate form
                const s = data.settings || {};
                document.getElementById('assistant_name').value = s.assistant_name || '';
                document.getElementById('welcome_message').value = s.welcome_message || '';
                document.getElementById('accent_color').value = s.accent_color || '#008060';
                document.getElementById('accent_color_picker').value = s.accent_color || '#008060';

                // Show form, hide skeletons
                document.querySelectorAll('.skeleton').forEach(el => el.style.display = 'none');
                document.getElementById('assistant_name').style.display = '';
                document.getElementById('welcome_message').style.display = '';
                document.getElementById('color-wrapper').style.display = '';
                document.getElementById('save-btn').disabled = false;
            } catch (err) {
                showToast('Failed to load settings: ' + err.message);
            }
        }

        // Sync color picker ↔ text input
        document.getElementById('accent_color_picker').addEventListener('input', function () {
            document.getElementById('accent_color').value = this.value;
        });
        document.getElementById('accent_color').addEventListener('input', function () {
            if (/^#[0-9A-Fa-f]{6}$/.test(this.value)) {
                document.getElementById('accent_color_picker').value = this.value;
            }
        });

        document.getElementById('settings-form').addEventListener('submit', async function (e) {
            e.preventDefault();
            const btn = document.getElementById('save-btn');
            btn.disabled = true;
            btn.textContent = 'Saving...';

            try {
                const body = {
                    assistant_name: document.getElementById('assistant_name').value,
                    welcome_message: document.getElementById('welcome_message').value,
                    accent_color: document.getElementById('accent_color').value || '#008060',
                };

                await apiFetch('/settings', {
                    method: 'PUT',
                    body: JSON.stringify(body),
                });

                showToast('Settings saved');
            } catch (err) {
                showToast('Failed to save: ' + err.message);
            } finally {
                btn.disabled = false;
                btn.textContent = 'Save Settings';
            }
        });

        // Load on page ready
        loadSettings();
    </script>
</body>
</html>
