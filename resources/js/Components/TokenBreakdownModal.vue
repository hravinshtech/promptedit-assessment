<script setup>
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    generation: {
        type: Object,
        default: null,
    },
});

const emit = defineEmits(['close']);

const formatMoney = (val) => {
    if (val === null || val === undefined) return '$0.0000';
    return `$${Number(val).toFixed(6)}`;
};
</script>

<template>
    <Modal :show="show" @close="emit('close')" max-width="lg">
        <div class="p-6" v-if="generation">
            <div class="flex items-center justify-between pb-3 border-b border-gray-100">
                <div>
                    <h3 class="text-base font-semibold text-gray-900">Token & Billing Breakdown</h3>
                    <p class="text-xs text-gray-500 font-mono">ID: {{ generation.id }}</p>
                </div>
                <button @click="emit('close')" class="text-gray-400 hover:text-gray-600">✕</button>
            </div>

            <!-- Status & Final Price -->
            <div class="my-4 p-4 rounded-xl bg-gray-50 border border-gray-100 grid grid-cols-2 gap-4">
                <div>
                    <span class="text-xs text-gray-500 block">User Charged (Actual)</span>
                    <span class="text-xl font-bold text-gray-900 font-mono">
                        {{ formatMoney(generation.actual_user_cost) }}
                    </span>
                    <span class="text-[11px] text-gray-500 block mt-0.5">Includes +25% markup</span>
                </div>
                <div>
                    <span class="text-xs text-gray-500 block">Pixelforge Cost (Provider)</span>
                    <span class="text-xl font-semibold text-indigo-700 font-mono">
                        {{ formatMoney(generation.actual_provider_cost) }}
                    </span>
                    <span class="text-[11px] text-gray-500 block mt-0.5">Exact published rate</span>
                </div>
            </div>

            <!-- Token Usage Table -->
            <div class="space-y-3">
                <h4 class="text-xs font-semibold text-gray-600 uppercase tracking-wider">Token Usage & Rates</h4>
                
                <div v-if="generation.usage" class="border border-gray-200 rounded-lg overflow-hidden text-xs">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-2 text-left font-medium text-gray-600">Token Type</th>
                                <th class="px-3 py-2 text-right font-medium text-gray-600">Tokens</th>
                                <th class="px-3 py-2 text-right font-medium text-gray-600">Rate / 1M</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white font-mono">
                            <tr>
                                <td class="px-3 py-2 text-gray-900 font-sans">Input Text</td>
                                <td class="px-3 py-2 text-right text-gray-700">{{ generation.usage.input_text_tokens?.toLocaleString() ?? 0 }}</td>
                                <td class="px-3 py-2 text-right text-gray-500">${{ generation.rates?.input_text_tokens ?? '5.00' }}</td>
                            </tr>
                            <tr>
                                <td class="px-3 py-2 text-gray-900 font-sans">Input Image</td>
                                <td class="px-3 py-2 text-right text-gray-700">{{ generation.usage.input_image_tokens?.toLocaleString() ?? 0 }}</td>
                                <td class="px-3 py-2 text-right text-gray-500">${{ generation.rates?.input_image_tokens ?? '10.00' }}</td>
                            </tr>
                            <tr>
                                <td class="px-3 py-2 text-gray-900 font-sans">Output Image</td>
                                <td class="px-3 py-2 text-right text-gray-700">{{ generation.usage.output_tokens?.toLocaleString() ?? 0 }}</td>
                                <td class="px-3 py-2 text-right text-gray-500">${{ generation.rates?.output_tokens ?? '40.00' }}</td>
                            </tr>
                            <tr>
                                <td class="px-3 py-2 text-gray-900 font-sans">Reasoning</td>
                                <td class="px-3 py-2 text-right text-gray-700">{{ generation.usage.reasoning_tokens?.toLocaleString() ?? 0 }}</td>
                                <td class="px-3 py-2 text-right text-gray-500">${{ generation.rates?.reasoning_tokens ?? '12.50' }}</td>
                            </tr>
                            <tr class="bg-gray-50 font-semibold">
                                <td class="px-3 py-2 text-gray-900 font-sans">Total Tokens</td>
                                <td class="px-3 py-2 text-right text-indigo-700">{{ generation.usage.total_tokens?.toLocaleString() ?? 0 }}</td>
                                <td class="px-3 py-2 text-right text-gray-400">—</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-else class="text-xs text-gray-500 italic bg-gray-50 p-3 rounded">
                    Tokens are not available until generation completes.
                </div>

                <!-- Parameters summary -->
                <div class="pt-2 text-xs text-gray-500 grid grid-cols-3 gap-2 border-t border-gray-100">
                    <div><span class="font-medium text-gray-700">Size:</span> {{ generation.size }}</div>
                    <div><span class="font-medium text-gray-700">Quality:</span> {{ generation.quality }}</div>
                    <div><span class="font-medium text-gray-700">Ref Images:</span> {{ generation.reference_images?.length || 0 }}</div>
                </div>
            </div>

            <div class="mt-6 flex justify-end">
                <SecondaryButton @click="emit('close')">Close</SecondaryButton>
            </div>
        </div>
    </Modal>
</template>
