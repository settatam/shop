<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Privacy Policy" />

    <div class="min-h-screen bg-white dark:bg-gray-950">
        <!-- Navigation -->
        <nav class="sticky top-0 z-50 border-b border-gray-100 bg-white/90 backdrop-blur-sm dark:border-gray-800 dark:bg-gray-950/90">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex h-16 items-center justify-between">
                    <Link href="/" class="flex items-center gap-2">
                        <img
                            src="https://fashionerize.nyc3.cdn.digitaloceanspaces.com/shopmata-logo.png"
                            alt="Shopmata"
                            class="h-8 w-auto"
                        />
                        <span class="text-lg font-bold text-gray-900 dark:text-white">Shopmata</span>
                    </Link>
                    <div class="flex items-center gap-6">
                        <Link href="/terms" class="text-sm font-medium text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white">
                            Terms of Service
                        </Link>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Content -->
        <div class="mx-auto max-w-3xl px-6 py-16 lg:px-8">
            <h1 class="text-3xl font-bold tracking-tight text-gray-900 dark:text-white">Privacy Policy</h1>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Last updated: February 28, 2026</p>

            <div class="mt-10 space-y-10 text-base leading-7 text-gray-600 dark:text-gray-400">
                <p>
                    At Shopmata, we take your privacy seriously. This Privacy Policy explains how we collect, use, disclose, and safeguard your information when you use our platform. Please read this policy carefully.
                </p>

                <!-- 1. Information We Collect -->
                <section>
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-white">1. Information We Collect</h2>

                    <h3 class="mt-4 text-lg font-medium text-gray-900 dark:text-white">Personal Information</h3>
                    <p class="mt-2">When you create an account or use our Service, we may collect:</p>
                    <ul class="mt-2 list-disc space-y-1 pl-6">
                        <li>Name and email address</li>
                        <li>Store name and business information</li>
                        <li>Phone number (if provided)</li>
                        <li>Billing and payment information</li>
                    </ul>

                    <h3 class="mt-4 text-lg font-medium text-gray-900 dark:text-white">Store & Product Data</h3>
                    <p class="mt-2">To provide our services, we collect and process:</p>
                    <ul class="mt-2 list-disc space-y-1 pl-6">
                        <li>Inventory and product information (descriptions, images, pricing, SKUs)</li>
                        <li>Order and transaction data</li>
                        <li>Customer information from your store</li>
                        <li>Consignment and memo records</li>
                        <li>Repair order details</li>
                    </ul>

                    <h3 class="mt-4 text-lg font-medium text-gray-900 dark:text-white">Usage Data</h3>
                    <p class="mt-2">We automatically collect certain information when you access the Service, including:</p>
                    <ul class="mt-2 list-disc space-y-1 pl-6">
                        <li>IP address and browser type</li>
                        <li>Device information and operating system</li>
                        <li>Pages visited and features used</li>
                        <li>Date, time, and duration of visits</li>
                        <li>Referring URLs</li>
                    </ul>
                </section>

                <!-- 2. How We Use Your Information -->
                <section>
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-white">2. How We Use Your Information</h2>
                    <p class="mt-3">We use the information we collect to:</p>
                    <ul class="mt-2 list-disc space-y-1 pl-6">
                        <li>Provide, maintain, and improve the Service</li>
                        <li>Process transactions and manage your account</li>
                        <li>Sync your inventory and orders across connected marketplace platforms</li>
                        <li>Send you technical notices, updates, and support messages</li>
                        <li>Respond to your comments, questions, and customer service requests</li>
                        <li>Monitor and analyze usage patterns and trends</li>
                        <li>Detect, investigate, and prevent fraudulent transactions and other illegal activities</li>
                        <li>Comply with legal obligations</li>
                    </ul>
                </section>

                <!-- 3. Data Sharing & Third-Party Services -->
                <section>
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-white">3. Data Sharing & Third-Party Services</h2>
                    <p class="mt-3">We may share your information with third parties in the following circumstances:</p>

                    <h3 class="mt-4 text-lg font-medium text-gray-900 dark:text-white">Marketplace Integrations</h3>
                    <p class="mt-2">
                        When you connect third-party platforms (such as Shopify, Amazon, eBay, Walmart, or Etsy), we share product and order data as necessary to sync your inventory and fulfill orders. Each platform's use of your data is governed by their own privacy policies.
                    </p>

                    <h3 class="mt-4 text-lg font-medium text-gray-900 dark:text-white">Payment Processors</h3>
                    <p class="mt-2">
                        We use third-party payment processors to handle billing. Your payment information is transmitted directly to these processors and is subject to their privacy policies.
                    </p>

                    <h3 class="mt-4 text-lg font-medium text-gray-900 dark:text-white">Analytics Providers</h3>
                    <p class="mt-2">
                        We may use third-party analytics services to help us understand how the Service is used. These services may collect information about your use of the Service using cookies and similar technologies.
                    </p>

                    <h3 class="mt-4 text-lg font-medium text-gray-900 dark:text-white">Legal Requirements</h3>
                    <p class="mt-2">
                        We may disclose your information if required to do so by law, or in the good faith belief that such action is necessary to comply with a legal obligation, protect our rights or property, prevent fraud, or protect the safety of our users.
                    </p>
                </section>

                <!-- 4. Cookies & Tracking -->
                <section>
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-white">4. Cookies & Tracking</h2>
                    <p class="mt-3">
                        We use cookies and similar tracking technologies to track activity on our Service and store certain information. Cookies are files with a small amount of data that are sent to your browser from a website and stored on your device.
                    </p>
                    <p class="mt-3">
                        We use session cookies to operate our Service and preference cookies to remember your settings. You can instruct your browser to refuse all cookies or to indicate when a cookie is being sent. However, if you do not accept cookies, you may not be able to use some portions of our Service.
                    </p>
                </section>

                <!-- 5. Data Retention -->
                <section>
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-white">5. Data Retention</h2>
                    <p class="mt-3">
                        We will retain your personal information only for as long as is necessary for the purposes set out in this Privacy Policy. We will retain and use your information to the extent necessary to comply with our legal obligations, resolve disputes, and enforce our agreements.
                    </p>
                    <p class="mt-3">
                        When you delete your account, we will delete or anonymize your personal information within 30 days, except where we are required to retain it for legal or regulatory purposes.
                    </p>
                </section>

                <!-- 6. Data Security -->
                <section>
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-white">6. Data Security</h2>
                    <p class="mt-3">
                        We implement appropriate technical and organizational measures to protect the security of your personal information, including encryption of data in transit and at rest, regular security assessments, and access controls.
                    </p>
                    <p class="mt-3">
                        However, no method of transmission over the Internet or method of electronic storage is 100% secure. While we strive to use commercially acceptable means to protect your personal information, we cannot guarantee its absolute security.
                    </p>
                </section>

                <!-- 7. Your Rights -->
                <section>
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-white">7. Your Rights</h2>
                    <p class="mt-3">Depending on your location, you may have the following rights regarding your personal data:</p>
                    <ul class="mt-2 list-disc space-y-1 pl-6">
                        <li><strong class="text-gray-900 dark:text-white">Access:</strong> Request a copy of the personal information we hold about you.</li>
                        <li><strong class="text-gray-900 dark:text-white">Correction:</strong> Request that we correct any inaccurate or incomplete personal information.</li>
                        <li><strong class="text-gray-900 dark:text-white">Deletion:</strong> Request that we delete your personal information, subject to certain exceptions.</li>
                        <li><strong class="text-gray-900 dark:text-white">Portability:</strong> Request a copy of your data in a structured, commonly used, and machine-readable format.</li>
                        <li><strong class="text-gray-900 dark:text-white">Opt-out:</strong> Opt out of the sale or sharing of your personal information (where applicable under CCPA).</li>
                    </ul>
                    <p class="mt-3">
                        To exercise any of these rights, please contact us at
                        <a href="mailto:support@shopmata.com" class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 dark:hover:text-indigo-300">support@shopmata.com</a>.
                        We will respond to your request within 30 days.
                    </p>
                </section>

                <!-- 8. Children's Privacy -->
                <section>
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-white">8. Children's Privacy</h2>
                    <p class="mt-3">
                        Our Service is not intended for use by children under the age of 18. We do not knowingly collect personal information from children under 18. If we become aware that we have collected personal information from a child under 18, we will take steps to delete that information.
                    </p>
                </section>

                <!-- 9. International Data Transfers -->
                <section>
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-white">9. International Data Transfers</h2>
                    <p class="mt-3">
                        Your information may be transferred to and maintained on servers located outside of your state, province, country, or other governmental jurisdiction where data protection laws may differ from those in your jurisdiction. By using the Service, you consent to such transfers.
                    </p>
                    <p class="mt-3">
                        We will take all steps reasonably necessary to ensure that your data is treated securely and in accordance with this Privacy Policy.
                    </p>
                </section>

                <!-- 10. Changes to This Policy -->
                <section>
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-white">10. Changes to This Policy</h2>
                    <p class="mt-3">
                        We may update this Privacy Policy from time to time. We will notify you of any changes by posting the new Privacy Policy on this page and updating the "Last updated" date. You are advised to review this Privacy Policy periodically for any changes.
                    </p>
                    <p class="mt-3">
                        Changes to this Privacy Policy are effective when they are posted on this page. Your continued use of the Service after any changes constitutes acceptance of the updated policy.
                    </p>
                </section>

                <!-- 11. Contact Information -->
                <section>
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-white">11. Contact Information</h2>
                    <p class="mt-3">
                        If you have any questions about this Privacy Policy, please contact us at
                        <a href="mailto:support@shopmata.com" class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 dark:hover:text-indigo-300">support@shopmata.com</a>.
                    </p>
                </section>
            </div>
        </div>

        <!-- Footer -->
        <footer class="border-t border-gray-100 bg-white dark:border-gray-800 dark:bg-gray-950">
            <div class="mx-auto max-w-7xl px-6 py-12 lg:px-8">
                <div class="md:flex md:items-center md:justify-between">
                    <Link href="/" class="flex items-center gap-2">
                        <img
                            src="https://fashionerize.nyc3.cdn.digitaloceanspaces.com/shopmata-logo.png"
                            alt="Shopmata"
                            class="h-6 w-auto"
                        />
                        <span class="text-sm font-semibold text-gray-900 dark:text-white">Shopmata</span>
                    </Link>
                    <div class="mt-6 flex flex-wrap gap-6 md:mt-0">
                        <Link href="/terms" class="text-sm text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white">Terms of Service</Link>
                        <Link href="/privacy" class="text-sm font-medium text-indigo-600 dark:text-indigo-400">Privacy Policy</Link>
                    </div>
                </div>
                <div class="mt-8 border-t border-gray-100 pt-8 dark:border-gray-800">
                    <p class="text-center text-sm leading-5 text-gray-500 dark:text-gray-400">
                        &copy; {{ new Date().getFullYear() }} Shopmata. All rights reserved.
                    </p>
                </div>
            </div>
        </footer>
    </div>
</template>
