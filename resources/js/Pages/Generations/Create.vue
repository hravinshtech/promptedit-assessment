<script setup>
import { ref, computed, watch } from 'vue';
import { Head, useForm, usePage, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DirectivesHelper from '@/Components/DirectivesHelper.vue';
import BuyCreditsModal from '@/Components/BuyCreditsModal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    rates: {
        type: Object,
        required: true,
    },
    sizes: {
        type: Array,
        required: true,
    },
    qualities: {
        type: Array,
        required: true,
    },
});

const page = usePage();
const showBuyModal = ref(false);

const form = useForm({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [],
});

const newImageUrl = ref('');

const addReferenceImage = () => {
    if (!newImageUrl.value) return;
    if (form.reference_images.length >= 4) return;
    
    try {
        new URL(newImageUrl.value);
        form.reference_images.push(newImageUrl.value.trim());
        newImageUrl.value = '';
    } catch (e) {
        alert('Please enter a valid URL (starting with http:// or https://)');
    }
};

const removeReferenceImage = (index) => {
    form.reference_images.splice(index, 1);
};

const handleInsertDirective = (tag) => {
    if (form.prompt.includes(tag)) return;
    form.prompt = form.prompt ? `${form.prompt.trim()} ${tag}` : tag;
};

// Client-side instant cost estimation (aligned exactly with backend PricingCalculator)
const sizeOutputTokens = {
    low: { '1024x1024': 280, '1536x1024': 420, '1024x1536': 420 },
    medium: { '1024x1024': 1100, '1536x1024': 1650, '1024x1536': 1650 },
    high: { '1024x1024': 4300, '1536x1024': 6450, '1024x1536': 6450 },
};

const reasoningRanges = {
    low: { min: 0, max: 500, est: 250 },
    medium: { min: 200, max: 2000, est: 1100 },
    high: { min: 1000, max: 8000, est: 4500 },
};

const estimation = computed(() => {
    const promptLen = form.prompt ? [...form.prompt].length : 0; // Unicode length
    const estTextTokens = promptLen > 0 ? Math.ceil(promptLen / 4) : 0;
    const minTextTokens = Math.max(1, Math.floor(estTextTokens * 0.85));
    const maxTextTokens = Math.ceil(estTextTokens * 1.15);

    const imageTokens = (form.reference_images.length) * 765;
    const outputTokens = sizeOutputTokens[form.quality]?.[form.size] || 1100;
    const reasoning = reasoningRanges[form.quality] || reasoningRanges.medium;

    const rateText = Number(props.rates.input_text_tokens || 5.0);
    const rateImage = Number(props.rates.input_image_tokens || 10.0);
    const rateOutput = Number(props.rates.output_tokens || 40.0);
    const rateReasoning = Number(props.rates.reasoning_tokens || 12.5);

    // Exact cost calculation per 1M tokens
    const calcCost = (text, image, output, reas) => {
        const sum = (text * rateText) + (image * rateImage) + (output * rateOutput) + (reas * rateReasoning);
        return sum / 1000000;
    };

    const minProviderCost = calcCost(minTextTokens, imageTokens, outputTokens, reasoning.min);
    const estProviderCost = calcCost(estTextTokens, imageTokens, outputTokens, reasoning.est);
    const maxProviderCost = calcCost(maxTextTokens, imageTokens, outputTokens, reasoning.max);

    const markup = 1.25; // 25% markup
    const minUserCost = minProviderCost * markup;
    const estUserCost = estProviderCost * markup;
    const maxUserCost = maxProviderCost * markup;

    return {
        promptLength: promptLen,
        textTokens: estTextTokens,
        imageTokens,
        outputTokens,
        reasoningRange: `${reasoning.min} - ${reasoning.max}`,
        reasoningEst: reasoning.est,
        totalTokensEst: estTextTokens + imageTokens + outputTokens + reasoning.est,
        providerEst: estProviderCost,
        userEst: estUserCost,
        userMin: minUserCost,
        userMax: maxUserCost,
    };
});

const userBalance = computed(() => Number(page.props.auth.user?.credit_balance || 0));
const canAfford = computed(() => userBalance.value >= estimation.value.userEst);

const submit = () => {
    form.post(route('generations.store'));
};
</script>

<template>
    <Head title="Create Image Generation" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-bold text-gray-900">Create Image Generation</h1>
                    <p class="text-xs text-gray-500">Configure parameters with live, transparent cost estimation before submitting</p>
                </div>
                <Link
                    :href="route('generations.index')"
                    class="text-xs font-semibold text-gray-600 hover:text-gray-900 flex items-center gap-1"
                >
                    ← Back to History
                </Link>
            </div>
        </template>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
            <!-- Left Column: Generation Options Form -->
            <div class="lg:col-span-7 space-y-6">
                <form @submit.prevent="submit" class="bg-white rounded-2xl border border-gray-200/80 p-6 shadow-xs space-y-6">
                    <!-- Prompt -->
                    <div>
                        <div class="flex justify-between items-center mb-1.5">
                            <InputLabel for="prompt" value="Prompt Description" class="font-semibold text-gray-800" />
                            <span :class="['text-xs font-mono', form.prompt.length > 3900 ? 'text-rose-600 font-bold' : 'text-gray-400']">
                                {{ form.prompt.length }} / 4,000 chars
                            </span>
                        </div>
                        <textarea
                            id="prompt"
                            v-model="form.prompt"
                            rows="4"
                            class="block w-full rounded-xl border-gray-300 shadow-xs focus:border-indigo-500 focus:ring-indigo-500 text-sm placeholder:text-gray-400 leading-relaxed"
                            placeholder="Describe what you want to generate in detail (e.g. A serene mountain lake at sunrise, highly detailed digital painting)..."
                            maxlength="4000"
                            required
                        ></textarea>
                        <InputError :message="form.errors.prompt" class="mt-1" />

                        <!-- Directives Helper -->
                        <DirectivesHelper :model-value="form.prompt" @insert-directive="handleInsertDirective" />
                    </div>

                    <!-- Size Selector -->
                    <div>
                        <InputLabel value="Image Dimensions & Aspect Ratio" class="font-semibold text-gray-800 mb-2" />
                        <div class="grid grid-cols-3 gap-3">
                            <label
                                :class="[
                                    'cursor-pointer rounded-xl border p-3 flex flex-col items-center text-center transition-all',
                                    form.size === '1024x1024'
                                        ? 'border-indigo-600 bg-indigo-50/50 ring-2 ring-indigo-600/20'
                                        : 'border-gray-200 hover:border-gray-300 bg-white'
                                ]"
                            >
                                <input type="radio" v-model="form.size" value="1024x1024" class="sr-only" />
                                <div class="w-8 h-8 rounded border-2 border-dashed border-gray-400 mb-2 flex items-center justify-center text-[10px] text-gray-500">
                                    1:1
                                </div>
                                <span class="text-xs font-bold text-gray-900">Square</span>
                                <span class="text-[11px] text-gray-500 font-mono mt-0.5">1024×1024</span>
                            </label>

                            <label
                                :class="[
                                    'cursor-pointer rounded-xl border p-3 flex flex-col items-center text-center transition-all',
                                    form.size === '1536x1024'
                                        ? 'border-indigo-600 bg-indigo-50/50 ring-2 ring-indigo-600/20'
                                        : 'border-gray-200 hover:border-gray-300 bg-white'
                                ]"
                            >
                                <input type="radio" v-model="form.size" value="1536x1024" class="sr-only" />
                                <div class="w-10 h-7 rounded border-2 border-dashed border-gray-400 mb-2 flex items-center justify-center text-[10px] text-gray-500">
                                    3:2
                                </div>
                                <span class="text-xs font-bold text-gray-900">Landscape</span>
                                <span class="text-[11px] text-gray-500 font-mono mt-0.5">1536×1024</span>
                            </label>

                            <label
                                :class="[
                                    'cursor-pointer rounded-xl border p-3 flex flex-col items-center text-center transition-all',
                                    form.size === '1024x1536'
                                        ? 'border-indigo-600 bg-indigo-50/50 ring-2 ring-indigo-600/20'
                                        : 'border-gray-200 hover:border-gray-300 bg-white'
                                ]"
                            >
                                <input type="radio" v-model="form.size" value="1024x1536" class="sr-only" />
                                <div class="w-7 h-10 rounded border-2 border-dashed border-gray-400 mb-2 flex items-center justify-center text-[10px] text-gray-500">
                                    2:3
                                </div>
                                <span class="text-xs font-bold text-gray-900">Portrait</span>
                                <span class="text-[11px] text-gray-500 font-mono mt-0.5">1024×1536</span>
                            </label>
                        </div>
                    </div>

                    <!-- Quality Selector -->
                    <div>
                        <InputLabel value="Quality & Reasoning Depth" class="font-semibold text-gray-800 mb-2" />
                        <div class="grid grid-cols-3 gap-3">
                            <label
                                :class="[
                                    'cursor-pointer rounded-xl border p-3 transition-all',
                                    form.quality === 'low'
                                        ? 'border-indigo-600 bg-indigo-50/50 ring-2 ring-indigo-600/20'
                                        : 'border-gray-200 hover:border-gray-300 bg-white'
                                ]"
                            >
                                <input type="radio" v-model="form.quality" value="low" class="sr-only" />
                                <span class="text-xs font-bold text-gray-900 block">Low</span>
                                <span class="text-[11px] text-gray-500 block mt-0.5">Fastest & cheapest (0-500 reasoning)</span>
                            </label>

                            <label
                                :class="[
                                    'cursor-pointer rounded-xl border p-3 transition-all',
                                    form.quality === 'medium'
                                        ? 'border-indigo-600 bg-indigo-50/50 ring-2 ring-indigo-600/20'
                                        : 'border-gray-200 hover:border-gray-300 bg-white'
                                ]"
                            >
                                <input type="radio" v-model="form.quality" value="medium" class="sr-only" />
                                <div class="flex items-center justify-between">
                                    <span class="text-xs font-bold text-gray-900">Medium</span>
                                    <span class="text-[10px] bg-indigo-100 text-indigo-700 px-1 rounded font-semibold">Default</span>
                                </div>
                                <span class="text-[11px] text-gray-500 block mt-0.5">Balanced detail (200-2k reasoning)</span>
                            </label>

                            <label
                                :class="[
                                    'cursor-pointer rounded-xl border p-3 transition-all',
                                    form.quality === 'high'
                                        ? 'border-indigo-600 bg-indigo-50/50 ring-2 ring-indigo-600/20'
                                        : 'border-gray-200 hover:border-gray-300 bg-white'
                                ]"
                            >
                                <input type="radio" v-model="form.quality" value="high" class="sr-only" />
                                <span class="text-xs font-bold text-gray-900 block">High</span>
                                <span class="text-[11px] text-gray-500 block mt-0.5">Maximum quality (1k-8k reasoning)</span>
                            </label>
                        </div>
                    </div>

                    <!-- Reference Images -->
                    <div>
                        <div class="flex justify-between items-center mb-1.5">
                            <InputLabel value="Reference Image URLs (Optional, max 4)" class="font-semibold text-gray-800" />
                            <span class="text-xs text-gray-400">765 tokens per image</span>
                        </div>
                        
                        <div class="flex gap-2">
                            <input
                                v-model="newImageUrl"
                                type="url"
                                placeholder="https://example.com/style-reference.png"
                                class="block flex-1 rounded-xl border-gray-300 shadow-xs focus:border-indigo-500 focus:ring-indigo-500 text-xs"
                                :disabled="form.reference_images.length >= 4"
                            />
                            <button
                                type="button"
                                @click="addReferenceImage"
                                :disabled="!newImageUrl || form.reference_images.length >= 4"
                                class="px-3 py-2 bg-gray-100 hover:bg-gray-200 disabled:opacity-50 text-gray-700 text-xs font-semibold rounded-xl transition"
                            >
                                Add URL
                            </button>
                        </div>

                        <!-- Image list -->
                        <div v-if="form.reference_images.length > 0" class="mt-3 space-y-2">
                            <div
                                v-for="(url, idx) in form.reference_images"
                                :key="idx"
                                class="flex items-center justify-between p-2 rounded-lg bg-gray-50 border border-gray-200 text-xs font-mono"
                            >
                                <span class="truncate flex-1 mr-2 text-gray-700">{{ url }}</span>
                                <button
                                    type="button"
                                    @click="removeReferenceImage(idx)"
                                    class="text-rose-600 hover:text-rose-800 font-bold px-1.5"
                                >
                                    ✕
                                </button>
                            </div>
                        </div>
                        <InputError :message="form.errors.reference_images" class="mt-1" />
                    </div>

                    <!-- Form Submission -->
                    <div class="pt-4 border-t border-gray-100 flex items-center justify-between">
                        <div>
                            <span class="text-xs text-gray-500 block">Up-front Hold Amount</span>
                            <span class="text-lg font-bold text-gray-900 font-mono">
                                ${{ estimation.userEst.toFixed(4) }} USD
                            </span>
                        </div>

                        <div class="flex items-center gap-3">
                            <button
                                v-if="!canAfford"
                                type="button"
                                @click="showBuyModal = true"
                                class="px-4 py-2 bg-amber-500 hover:bg-amber-600 text-white text-xs font-bold rounded-xl shadow-xs transition"
                            >
                                + Buy Credits
                            </button>
                            <button
                                type="submit"
                                :disabled="form.processing || !form.prompt || !canAfford"
                                class="px-6 py-2.5 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white font-semibold text-sm rounded-xl shadow-xs transition flex items-center gap-2"
                            >
                                <span v-if="form.processing" class="inline-block animate-spin">⚙️</span>
                                <span>{{ form.processing ? 'Submitting...' : 'Generate Image' }}</span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Right Column: Live Transparent Cost Estimation Widget -->
            <div class="lg:col-span-5 space-y-6">
                <div class="bg-white rounded-2xl border border-gray-200/80 p-6 shadow-xs space-y-5 sticky top-24">
                    <div class="flex items-center justify-between pb-3 border-b border-gray-100">
                        <div class="flex items-center gap-2">
                            <span class="text-base">📊</span>
                            <h2 class="text-base font-bold text-gray-900">Up-front Price Preview</h2>
                        </div>
                        <span class="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200">
                            Transparent
                        </span>
                    </div>

                    <!-- Estimated Final Price Card -->
                    <div class="p-4 rounded-xl bg-gradient-to-br from-indigo-50/80 to-purple-50/40 border border-indigo-100">
                        <span class="text-xs text-indigo-900/70 font-medium block">Estimated User Price (+25% markup)</span>
                        <div class="flex items-baseline gap-2 mt-1">
                            <span class="text-2xl font-black text-indigo-900 font-mono">
                                ${{ estimation.userEst.toFixed(4) }}
                            </span>
                            <span class="text-xs text-indigo-700 font-mono">
                                (${{ estimation.userMin.toFixed(4) }} – ${{ estimation.userMax.toFixed(4) }})
                            </span>
                        </div>
                        <p class="text-[11px] text-indigo-800/80 mt-1.5 leading-relaxed">
                            Pixelforge base cost is estimated at <strong>${{ estimation.providerEst.toFixed(4) }}</strong>. The exact price is recorded once generation completes. Failed generations cost $0.
                        </p>
                    </div>

                    <!-- User Balance Status -->
                    <div class="p-3.5 rounded-xl border flex items-center justify-between" :class="canAfford ? 'bg-gray-50 border-gray-200' : 'bg-rose-50 border-rose-200'">
                        <div>
                            <span class="text-xs text-gray-500 block">Your Available Balance</span>
                            <span class="font-bold font-mono text-sm" :class="canAfford ? 'text-gray-900' : 'text-rose-700'">
                                ${{ userBalance.toFixed(4) }} USD
                            </span>
                        </div>
                        <div v-if="!canAfford" class="text-right">
                            <span class="text-[11px] text-rose-600 font-semibold block">Need ${{ (estimation.userEst - userBalance).toFixed(4) }} more</span>
                            <button
                                @click="showBuyModal = true"
                                type="button"
                                class="text-xs text-indigo-600 hover:text-indigo-800 font-bold underline"
                            >
                                Top up now
                            </button>
                        </div>
                        <div v-else class="text-emerald-600 text-xs font-semibold flex items-center gap-1">
                            <span>✓</span> Sufficient
                        </div>
                    </div>

                    <!-- Token Breakdown Detailed Breakdown -->
                    <div class="space-y-2 text-xs">
                        <span class="font-semibold text-gray-700 uppercase tracking-wider text-[10px] block">Token Usage Breakdown</span>

                        <div class="flex justify-between py-1.5 border-b border-gray-100 text-gray-600">
                            <span>Input Text (~{{ estimation.promptLength }} chars / 4)</span>
                            <span class="font-mono font-medium text-gray-900">~{{ estimation.textTokens }} tokens</span>
                        </div>

                        <div class="flex justify-between py-1.5 border-b border-gray-100 text-gray-600">
                            <span>Reference Images ({{ form.reference_images.length }} × 765)</span>
                            <span class="font-mono font-medium text-gray-900">{{ estimation.imageTokens }} tokens</span>
                        </div>

                        <div class="flex justify-between py-1.5 border-b border-gray-100 text-gray-600">
                            <span>Output Image ({{ form.quality }}, {{ form.size }})</span>
                            <span class="font-mono font-medium text-gray-900">{{ estimation.outputTokens }} tokens</span>
                        </div>

                        <div class="flex justify-between py-1.5 border-b border-gray-100 text-gray-600">
                            <span>Reasoning Depth ({{ form.quality }})</span>
                            <span class="font-mono font-medium text-gray-900">~{{ estimation.reasoningEst }} ({{ estimation.reasoningRange }})</span>
                        </div>

                        <div class="flex justify-between pt-2 font-bold text-gray-900">
                            <span>Estimated Total Tokens</span>
                            <span class="font-mono text-indigo-700">~{{ estimation.totalTokensEst.toLocaleString() }} tokens</span>
                        </div>
                    </div>

                    <!-- Published Rates Snapshot -->
                    <div class="p-3 bg-gray-50 rounded-xl text-[11px] text-gray-500 space-y-1">
                        <span class="font-semibold text-gray-700 block">Published Token Rates (per 1M):</span>
                        <div class="grid grid-cols-2 gap-1 font-mono text-[10px]">
                            <div>Text: ${{ rates.input_text_tokens }}</div>
                            <div>Images: ${{ rates.input_image_tokens }}</div>
                            <div>Output: ${{ rates.output_tokens }}</div>
                            <div>Reasoning: ${{ rates.reasoning_tokens }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <BuyCreditsModal :show="showBuyModal" @close="showBuyModal = false" />
    </AuthenticatedLayout>
</template>
