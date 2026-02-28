<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Terms of Service" />

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
                        <Link href="/privacy" class="text-sm font-medium text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white">
                            Privacy Policy
                        </Link>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Content -->
        <div class="mx-auto max-w-3xl px-6 py-16 lg:px-8">
            <h1 class="text-3xl font-bold tracking-tight text-gray-900 dark:text-white">Terms of Service</h1>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Last updated: February 28, 2026</p>

            <div class="mt-10 space-y-10 text-base leading-7 text-gray-600 dark:text-gray-400">
                <!-- 1. Acceptance of Terms -->
                <section>
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-white">1. Acceptance of Terms</h2>
                    <p class="mt-3">
                        By accessing or using Shopmata ("Service"), you agree to be bound by these Terms of Service ("Terms"). If you do not agree to these Terms, you may not access or use the Service. These Terms apply to all visitors, users, and others who access or use the Service.
                    </p>
                    <p class="mt-3">
                        By creating an account, you represent that you are at least 18 years old and have the legal authority to enter into these Terms on behalf of yourself or the entity you represent.
                    </p>
                </section>

                <!-- 2. Description of Services -->
                <section>
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-white">2. Description of Services</h2>
                    <p class="mt-3">
                        Shopmata is a software-as-a-service (SaaS) platform designed for jewelry retailers. The Service provides inventory management, multi-channel selling, consignment and memo tracking, repair order management, point of sale capabilities, and related business tools. Shopmata enables integration with third-party marketplaces and e-commerce platforms to help you manage your jewelry business from a single dashboard.
                    </p>
                </section>

                <!-- 3. Account Registration & Responsibilities -->
                <section>
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-white">3. Account Registration & Responsibilities</h2>
                    <p class="mt-3">
                        To use certain features of the Service, you must create an account. You agree to provide accurate, current, and complete information during the registration process and to update such information to keep it accurate, current, and complete.
                    </p>
                    <p class="mt-3">
                        You are responsible for safeguarding the password you use to access the Service and for any activities or actions under your account. You agree not to share your login credentials with any third party. You must notify us immediately upon becoming aware of any unauthorized use of your account.
                    </p>
                </section>

                <!-- 4. Acceptable Use Policy -->
                <section>
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-white">4. Acceptable Use Policy</h2>
                    <p class="mt-3">You agree not to use the Service to:</p>
                    <ul class="mt-3 list-disc space-y-2 pl-6">
                        <li>Violate any applicable local, state, national, or international law or regulation.</li>
                        <li>Infringe upon or violate the intellectual property rights or any other rights of others.</li>
                        <li>Upload, transmit, or distribute any viruses, malware, or other harmful code.</li>
                        <li>Attempt to gain unauthorized access to the Service, other accounts, or any related systems or networks.</li>
                        <li>Use the Service for any fraudulent or deceptive purpose, including the sale of counterfeit goods.</li>
                        <li>Interfere with or disrupt the integrity or performance of the Service.</li>
                        <li>Collect or harvest any personally identifiable information from the Service without proper authorization.</li>
                    </ul>
                </section>

                <!-- 5. Intellectual Property Rights -->
                <section>
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-white">5. Intellectual Property Rights</h2>
                    <p class="mt-3">
                        The Service and its original content, features, and functionality are and will remain the exclusive property of Shopmata and its licensors. The Service is protected by copyright, trademark, and other laws. Our trademarks may not be used in connection with any product or service without the prior written consent of Shopmata.
                    </p>
                    <p class="mt-3">
                        You retain all rights to the content you upload, submit, or display through the Service. By uploading content, you grant Shopmata a worldwide, non-exclusive, royalty-free license to use, process, and display such content solely for the purpose of providing and improving the Service.
                    </p>
                </section>

                <!-- 6. Third-Party Integrations -->
                <section>
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-white">6. Third-Party Integrations</h2>
                    <p class="mt-3">
                        The Service integrates with third-party platforms including but not limited to Shopify, Amazon, eBay, Walmart, and Etsy. Your use of these integrations is subject to the terms and conditions of the respective third-party services. Shopmata is not responsible for the availability, accuracy, or content of any third-party service.
                    </p>
                    <p class="mt-3">
                        By connecting a third-party account, you authorize Shopmata to access and interact with that account on your behalf within the scope of the functionality provided by the Service. You are responsible for ensuring your use of third-party integrations complies with the respective platform's terms.
                    </p>
                </section>

                <!-- 7. Payment Terms & Billing -->
                <section>
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-white">7. Payment Terms & Billing</h2>
                    <p class="mt-3">
                        Certain features of the Service may require payment of fees. You agree to pay all applicable fees associated with your use of the Service. Fees are non-refundable except as required by law or as explicitly stated in these Terms.
                    </p>
                    <p class="mt-3">
                        We reserve the right to change our pricing at any time. If we change pricing for a subscription you have already purchased, we will provide at least 30 days' notice before the price change takes effect.
                    </p>
                </section>

                <!-- 8. Data Handling & Privacy -->
                <section>
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-white">8. Data Handling & Privacy</h2>
                    <p class="mt-3">
                        Your use of the Service is also governed by our
                        <Link href="/privacy" class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 dark:hover:text-indigo-300">Privacy Policy</Link>,
                        which describes how we collect, use, and protect your information. By using the Service, you consent to the data practices described in the Privacy Policy.
                    </p>
                </section>

                <!-- 9. Limitation of Liability & Disclaimers -->
                <section>
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-white">9. Limitation of Liability & Disclaimers</h2>
                    <p class="mt-3">
                        THE SERVICE IS PROVIDED ON AN "AS IS" AND "AS AVAILABLE" BASIS WITHOUT WARRANTIES OF ANY KIND, WHETHER EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO IMPLIED WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE, AND NON-INFRINGEMENT.
                    </p>
                    <p class="mt-3">
                        IN NO EVENT SHALL SHOPMATA, ITS DIRECTORS, EMPLOYEES, PARTNERS, AGENTS, SUPPLIERS, OR AFFILIATES BE LIABLE FOR ANY INDIRECT, INCIDENTAL, SPECIAL, CONSEQUENTIAL, OR PUNITIVE DAMAGES, INCLUDING WITHOUT LIMITATION, LOSS OF PROFITS, DATA, USE, GOODWILL, OR OTHER INTANGIBLE LOSSES, RESULTING FROM YOUR ACCESS TO OR USE OF OR INABILITY TO ACCESS OR USE THE SERVICE.
                    </p>
                    <p class="mt-3">
                        Shopmata does not warrant that the Service will be uninterrupted, timely, secure, or error-free. We do not guarantee the accuracy or completeness of any information provided through the Service, including product data synced from third-party platforms.
                    </p>
                </section>

                <!-- 10. Termination & Account Suspension -->
                <section>
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-white">10. Termination & Account Suspension</h2>
                    <p class="mt-3">
                        We may terminate or suspend your account and access to the Service immediately, without prior notice or liability, for any reason, including without limitation if you breach these Terms.
                    </p>
                    <p class="mt-3">
                        You may terminate your account at any time by contacting us. Upon termination, your right to use the Service will immediately cease. We may retain certain data as required by law or for legitimate business purposes.
                    </p>
                </section>

                <!-- 11. Governing Law & Dispute Resolution -->
                <section>
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-white">11. Governing Law & Dispute Resolution</h2>
                    <p class="mt-3">
                        These Terms shall be governed by and construed in accordance with the laws of the State of California, United States, without regard to its conflict of law provisions.
                    </p>
                    <p class="mt-3">
                        Any dispute arising out of or relating to these Terms or the Service shall first be attempted to be resolved through good-faith negotiation. If the dispute cannot be resolved through negotiation, it shall be submitted to binding arbitration in accordance with the rules of the American Arbitration Association.
                    </p>
                </section>

                <!-- 12. Changes to Terms -->
                <section>
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-white">12. Changes to Terms</h2>
                    <p class="mt-3">
                        We reserve the right to modify or replace these Terms at any time. If a revision is material, we will provide at least 30 days' notice prior to any new terms taking effect. What constitutes a material change will be determined at our sole discretion.
                    </p>
                    <p class="mt-3">
                        By continuing to access or use our Service after those revisions become effective, you agree to be bound by the revised terms.
                    </p>
                </section>

                <!-- 13. Contact Information -->
                <section>
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-white">13. Contact Information</h2>
                    <p class="mt-3">
                        If you have any questions about these Terms, please contact us at
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
                        <Link href="/terms" class="text-sm font-medium text-indigo-600 dark:text-indigo-400">Terms of Service</Link>
                        <Link href="/privacy" class="text-sm text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white">Privacy Policy</Link>
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
