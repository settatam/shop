<?php

namespace App\Services\Leads;

use App\Models\Lead;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Support\Facades\DB;

class LeadConversionService
{
    /**
     * Convert a lead to a transaction (buy).
     * Copies lead data and items to a new Transaction + TransactionItems.
     * Sets lead.transaction_id to the created transaction.
     *
     * @throws \RuntimeException If the lead has already been converted.
     */
    public function convertToTransaction(Lead $lead): Transaction
    {
        if ($lead->transaction_id !== null) {
            throw new \RuntimeException("Lead #{$lead->lead_number} has already been converted to transaction #{$lead->transaction_id}.");
        }

        return DB::transaction(function () use ($lead) {
            $transaction = Transaction::create([
                'store_id' => $lead->store_id,
                'warehouse_id' => $lead->warehouse_id,
                'customer_id' => $lead->customer_id,
                'shipping_address_id' => $lead->shipping_address_id,
                'user_id' => $lead->user_id,
                'assigned_to' => $lead->assigned_to,
                'status' => Transaction::STATUS_PAYMENT_PROCESSED,
                'type' => $lead->type,
                'source' => $lead->source,
                'preliminary_offer' => $lead->preliminary_offer,
                'final_offer' => $lead->final_offer,
                'estimated_value' => $lead->estimated_value,
                'payment_method' => $lead->payment_method,
                'payment_details' => $lead->payment_details,
                'bin_location' => $lead->bin_location,
                'customer_notes' => $lead->customer_notes,
                'internal_notes' => $lead->internal_notes,
                'customer_description' => $lead->customer_description,
                'customer_amount' => $lead->customer_amount,
                'customer_categories' => $lead->customer_categories,
                'outbound_tracking_number' => $lead->outbound_tracking_number,
                'outbound_carrier' => $lead->outbound_carrier ?? 'fedex',
                'return_tracking_number' => $lead->return_tracking_number,
                'return_carrier' => $lead->return_carrier ?? 'fedex',
                'offer_given_at' => $lead->offer_given_at,
                'offer_accepted_at' => $lead->offer_accepted_at,
                'payment_processed_at' => $lead->payment_processed_at,
                'kit_sent_at' => $lead->kit_sent_at,
                'kit_delivered_at' => $lead->kit_delivered_at,
                'items_received_at' => $lead->items_received_at,
                'items_reviewed_at' => $lead->items_reviewed_at,
                'return_shipped_at' => $lead->return_shipped_at,
                'return_delivered_at' => $lead->return_delivered_at,
            ]);

            // Copy lead items to transaction items
            $lead->load('items');
            foreach ($lead->items as $leadItem) {
                TransactionItem::create([
                    'transaction_id' => $transaction->id,
                    'category_id' => $leadItem->category_id,
                    'product_id' => $leadItem->product_id,
                    'bucket_id' => $leadItem->bucket_id,
                    'sku' => $leadItem->sku,
                    'title' => $leadItem->title,
                    'description' => $leadItem->description,
                    'quantity' => $leadItem->quantity,
                    'price' => $leadItem->price,
                    'buy_price' => $leadItem->buy_price,
                    'dwt' => $leadItem->dwt,
                    'precious_metal' => $leadItem->precious_metal,
                    'condition' => $leadItem->condition,
                    'attributes' => $leadItem->attributes,
                    'is_added_to_inventory' => $leadItem->is_added_to_inventory,
                    'is_added_to_bucket' => $leadItem->is_added_to_bucket,
                    'date_added_to_inventory' => $leadItem->date_added_to_inventory,
                    'reviewed_at' => $leadItem->reviewed_at,
                    'reviewed_by' => $leadItem->reviewed_by,
                    'ai_research' => $leadItem->ai_research,
                    'ai_research_generated_at' => $leadItem->ai_research_generated_at,
                    'web_search_results' => $leadItem->web_search_results,
                    'web_search_generated_at' => $leadItem->web_search_generated_at,
                    'market_price_data' => $leadItem->market_price_data,
                ]);
            }

            // Copy images from lead to transaction
            foreach ($lead->images as $image) {
                $transaction->images()->create([
                    'store_id' => $lead->store_id,
                    'url' => $image->url,
                    'path' => $image->path,
                    'disk' => $image->disk,
                    'alt_text' => $image->alt_text,
                    'sort_order' => $image->sort_order,
                    'is_primary' => $image->is_primary,
                ]);
            }

            // Link the lead to the created transaction
            $lead->update(['transaction_id' => $transaction->id]);

            return $transaction;
        });
    }
}
