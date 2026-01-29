<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Plug, ExternalLink } from 'lucide-vue-next';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Integrations', href: '/integrations' },
];

const platforms = [
    {
        name: 'Shopify',
        description: 'Sync products and orders with your Shopify store',
        logo: '/images/platforms/shopify.svg',
        status: 'available',
    },
    {
        name: 'eBay',
        description: 'List products and manage orders on eBay',
        logo: '/images/platforms/ebay.svg',
        status: 'available',
    },
    {
        name: 'Amazon',
        description: 'Sell on Amazon Marketplace',
        logo: '/images/platforms/amazon.svg',
        status: 'available',
    },
    {
        name: 'Etsy',
        description: 'Connect your Etsy shop for handmade and vintage items',
        logo: '/images/platforms/etsy.svg',
        status: 'available',
    },
    {
        name: 'Walmart',
        description: 'Sell on Walmart Marketplace',
        logo: '/images/platforms/walmart.svg',
        status: 'available',
    },
    {
        name: 'WooCommerce',
        description: 'Integrate with your WooCommerce store',
        logo: '/images/platforms/woocommerce.svg',
        status: 'available',
    },
];
</script>

<template>
    <Head title="Integrations" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <div>
                <h1 class="text-2xl font-semibold">Integrations</h1>
                <p class="text-sm text-muted-foreground">
                    Connect your e-commerce platforms to sync products and orders
                </p>
            </div>

            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                <Card v-for="platform in platforms" :key="platform.name">
                    <CardHeader>
                        <div class="flex items-center justify-between">
                            <CardTitle class="text-lg">{{ platform.name }}</CardTitle>
                            <Badge variant="secondary">Available</Badge>
                        </div>
                        <CardDescription>
                            {{ platform.description }}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Button class="w-full" variant="outline">
                            <Plug class="mr-2 h-4 w-4" />
                            Connect
                        </Button>
                    </CardContent>
                </Card>
            </div>

            <Card class="mt-4">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        <ExternalLink class="h-5 w-5" />
                        AI Services
                    </CardTitle>
                    <CardDescription>
                        Configure AI-powered features for your store
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="flex items-center justify-between p-4 border rounded-lg">
                        <div>
                            <h4 class="font-medium">Description Generator</h4>
                            <p class="text-sm text-muted-foreground">
                                AI-powered product descriptions optimized for each platform
                            </p>
                        </div>
                        <Badge>Configured</Badge>
                    </div>
                    <div class="flex items-center justify-between p-4 border rounded-lg">
                        <div>
                            <h4 class="font-medium">Auto-Categorization</h4>
                            <p class="text-sm text-muted-foreground">
                                Automatically categorize products for each marketplace
                            </p>
                        </div>
                        <Badge>Configured</Badge>
                    </div>
                    <div class="flex items-center justify-between p-4 border rounded-lg">
                        <div>
                            <h4 class="font-medium">Price Optimizer</h4>
                            <p class="text-sm text-muted-foreground">
                                Get AI-powered pricing suggestions based on market data
                            </p>
                        </div>
                        <Badge>Configured</Badge>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
