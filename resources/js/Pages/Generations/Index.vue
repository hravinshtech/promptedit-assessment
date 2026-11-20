<script setup>
import { ref, onMounted, onUnmounted, computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TokenBreakdownModal from '@/Components/TokenBreakdownModal.vue';
import axios from 'axios';

const props = defineProps({
    generations: {
        type: Object,
        required: true,
    },
    rates: {
        type: Object,
        required: true,
    },
    pendingCount: {
        type: Number,
        default: 0,
    },
});

const page = usePage();
const generationsList = ref([...props.generations.data]);
const selectedGeneration = ref(null);
const showBreakdownModal = ref(false);
const lightboxImage = ref(null);
const activeFilter = ref('all');
const isPolling = ref(false);
let pollTimer = null;

// Filter generations
const filteredGenerations = computed(() => {
    if (activeFilter.value === 'all') return generationsList.value;
    return generationsList.value.filter(g => g.status === activeFilter.value);
});

// Check if any generation is queued or processing
const hasPendingGenerations = computed(() => {
    return generationsList.value.some(g => g.status === 'queued' || g.status === 'processing');
});

// Live Polling
const pollPending = async () => {
    if (!hasPendingGenerations.value) {
        stopPolling();
        return;
    }

    try {
        isPolling.value = true;
        const response = await axios.get(route('generations.index', { json: 1 }));
        if (response.data && response.data.generations) {
            // Merge updated generations
            const updatedMap = new Map(response.data.generations.map(g => [g.id, g]));
            generationsList.value = generationsList.value.map(g => {
                return updatedMap.has(g.id) ? updatedMap.get(g.id) : g;
            });

            // Update user balance in Inertia props
            if (response.data.credit_balance !== undefined) {
                page.props.auth.user.credit_balance = response.data.credit_balance;
            }
        }
    } catch (e) {
        console.warn('Poll error:', e);
    } finally {
        isPolling.value = false;
        if (!hasPendingGenerations.value) {
            stopPolling();
        }
    }
};

const startPolling = () => {
    if (pollTimer) clearInterval(pollTimer);
    if (hasPendingGenerations.value) {
        pollTimer = setInterval(pollPending, 3000);
    }
};

const stopPolling = () => {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
};

onMounted(() => {
    startPolling();
});

onUnmounted(() => {
    stopPolling();
});

// Manual Sync
const syncSingle = async (gen) => {
    try {
        const response = await axios.post(route('generations.sync', { generation: gen.id }));
        if (response.data && response.data.generation) {
            const index = generationsList.value.findIndex(g => g.id === gen.id);
            if (index !== -1) {
                generationsList.value[index] = response.data.generation;
            }
            if (response.data.credit_balance !== undefined) {
                page.props.auth.user.credit_balance = response.data.credit_balance;
            }
        }
    } catch (e) {
        alert('Failed to sync generation with Pixelforge: ' + (e.response?.data?.message || e.message));
    }
};

const openTokenModal = (gen) => {
    selectedGeneration.value = gen;
    showBreakdownModal.value = true;
};

const formatDate = (dateString) => {
    if (!dateString) return '';
    const date = new Date(dateString);
    return date.toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

const formatCost = (val) => {
    return `$${Number(val || 0).toFixed(4)}`;
};
</script>

<template>
    <Head title="Generations History" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <div class="flex items-center gap-3">
                        <h1 class="text-xl font-bold text-gray-900">Generations Studio</h1>
                        <span
                            v-if="hasPendingGenerations"
                            class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-indigo-50 text-indigo-700 border border-indigo-200"
                        >
                            <span class="w-1.5 h-1.5 rounded-full bg-indigo-600 animate-ping"></span>
                            Live Updates Active
                        </span>
                    </div>
                    <p class="text-xs text-gray-500 mt-0.5">Real-time status tracking, token usage audit, and image generation records</p>
                </div>

                <div class="flex items-center gap-3">
                    <Link
                        :href="route('generations.create')"
                        class="inline-flex items-center gap-1.5 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-xl shadow-xs transition"
                    >
                        <span>+</span>
                        <span>New Generation</span>
                    </Link>
                </div>
            </div>
        </template>

        <!-- Filters & Stats Bar -->
        <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center bg-white p-1 rounded-xl border border-gray-200/80 shadow-2xs">
                <button
                    v-for="filter in [
                        { key: 'all', label: 'All' },
                        { key: 'succeeded', label: 'Succeeded' },
                        { key: 'queued', label: 'Queued' },
                        { key: 'processing', label: 'Processing' },
                        { key: 'failed', label: 'Failed' },
                    ]"
                    :key="filter.key"
                    @click="activeFilter = filter.key"
                    :class="[
                        'px-3 py-1.5 text-xs font-medium rounded-lg transition-all capitalize',
                        activeFilter === filter.key
                            ? 'bg-indigo-600 text-white shadow-xs font-semibold'
                            : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50'
                    ]"
                >
                    {{ filter.label }}
                </button>
            </div>

            <div class="text-xs text-gray-500 font-mono">
                Showing {{ filteredGenerations.length }} of {{ generations.total }} generations
            </div>
        </div>

        <!-- Empty State -->
        <div
            v-if="filteredGenerations.length === 0"
            class="bg-white rounded-2xl border border-dashed border-gray-300 p-12 text-center"
        >
            <div class="w-12 h-12 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center mx-auto text-2xl mb-3">
                🎨
            </div>
            <h3 class="text-base font-semibold text-gray-900">No generations found</h3>
            <p class="text-xs text-gray-500 max-w-sm mx-auto mt-1 mb-5">
                {{ activeFilter === 'all'
                    ? 'Start generating placeholder images with Pixelforge. Your balance will be charged only for what you generate.'
                    : `No generations with status "${activeFilter}".`
                }}
            </p>
            <Link
                :href="route('generations.create')"
                class="inline-flex items-center px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-xl shadow-xs transition"
            >
                Create your first generation →
            </Link>
        </div>

        <!-- Generation Cards Grid -->
        <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <div
                v-for="gen in filteredGenerations"
                :key="gen.id"
                class="bg-white rounded-2xl border border-gray-200/80 overflow-hidden shadow-xs hover:shadow-md transition-shadow flex flex-col justify-between"
            >
                <!-- Card Header -->
                <div class="p-4 border-b border-gray-100 flex items-center justify-between">
                    <!-- Status Badge -->
                    <div class="flex items-center gap-1.5">
                        <span
                            v-if="gen.status === 'succeeded'"
                            class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200"
                        >
                            <span>✓</span> Succeeded
                        </span>
                        <span
                            v-else-if="gen.status === 'processing'"
                            class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-blue-50 text-blue-700 border border-blue-200"
                        >
                            <span class="w-1.5 h-1.5 rounded-full bg-blue-600 animate-ping"></span> Processing
                        </span>
                        <span
                            v-else-if="gen.status === 'queued'"
                            class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-50 text-amber-700 border border-amber-200"
                        >
                            <span>⏳</span> Queued
                        </span>
                        <span
                            v-else-if="gen.status === 'failed'"
                            class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-rose-50 text-rose-700 border border-rose-200"
                        >
                            <span>✕</span> Failed
                        </span>

                        <span class="text-[11px] text-gray-400 font-mono">{{ gen.size }}</span>
                    </div>

                    <div class="flex items-center gap-2">
                        <span class="text-[11px] text-gray-400 font-mono">{{ formatDate(gen.created_at) }}</span>
                        <!-- Manual Sync for Pending -->
                        <button
                            v-if="gen.status === 'queued' || gen.status === 'processing'"
                            @click="syncSingle(gen)"
                            type="button"
                            title="Poll Pixelforge API now"
                            class="p-1 text-gray-400 hover:text-indigo-600 rounded transition"
                        >
                            🔄
                        </button>
                    </div>
                </div>

                <!-- Image Preview or Placeholder -->
                <div class="relative bg-gray-100 aspect-video flex items-center justify-center overflow-hidden">
                    <!-- Succeeded Image -->
                    <div v-if="gen.status === 'succeeded' && gen.image_url" class="w-full h-full group relative">
                        <img
                            :src="gen.image_url"
                            :alt="gen.prompt"
                            class="w-full h-full object-cover cursor-pointer hover:scale-105 transition-transform duration-300"
                            @click="lightboxImage = gen.image_url"
                            loading="lazy"
                        />
                        <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center gap-2">
                            <button
                                @click="lightboxImage = gen.image_url"
                                class="px-3 py-1.5 bg-white text-gray-900 rounded-lg text-xs font-semibold shadow hover:bg-gray-100"
                            >
                                View Full
                            </button>
                            <a
                                :href="gen.image_url"
                                target="_blank"
                                download
                                class="px-3 py-1.5 bg-indigo-600 text-white rounded-lg text-xs font-semibold shadow hover:bg-indigo-700"
                            >
                                Download
                            </a>
                        </div>
                    </div>

                    <!-- Processing / Queued Animation -->
                    <div v-else-if="gen.status === 'queued' || gen.status === 'processing'" class="p-6 text-center space-y-2">
                        <div class="w-10 h-10 border-3 border-indigo-200 border-t-indigo-600 rounded-full animate-spin mx-auto"></div>
                        <p class="text-xs font-medium text-gray-700">
                            {{ gen.status === 'queued' ? 'Waiting in Pixelforge queue...' : 'Generating image tokens...' }}
                        </p>
                        <p class="text-[11px] text-gray-400">Takes 5 to 30 seconds</p>
                    </div>

                    <!-- Failed State -->
                    <div v-else-if="gen.status === 'failed'" class="p-6 text-center space-y-2">
                        <div class="w-10 h-10 bg-rose-100 text-rose-600 rounded-full flex items-center justify-center mx-auto text-lg font-bold">
                            ✕
                        </div>
                        <p class="text-xs font-semibold text-rose-700">Generation Failed</p>
                        <p class="text-[11px] text-gray-600 px-4 line-clamp-2">
                            {{ gen.error_message || 'The model could not produce an image for this prompt.' }}
                        </p>
                        <span class="inline-block text-[10px] bg-rose-50 border border-rose-200 text-rose-800 px-2 py-0.5 rounded font-mono">
                            Cost: $0.00 (Unbilled)
                        </span>
                    </div>
                </div>

                <!-- Prompt & Expiry info -->
                <div class="p-4 space-y-3 flex-1 flex flex-col justify-between">
                    <div>
                        <p class="text-xs text-gray-800 line-clamp-2 leading-relaxed" :title="gen.prompt">
                            "{{ gen.prompt }}"
                        </p>

                        <!-- Expiration warning if succeeded -->
                        <div v-if="gen.status === 'succeeded' && gen.image_url_expires_at" class="mt-2 text-[10px] text-gray-400 flex items-center gap-1">
                            <span>⏱️</span>
                            <span>Signed URL expires 24h after completion</span>
                        </div>
                    </div>

                    <!-- Cost & Breakdown Footer -->
                    <div class="pt-3 border-t border-gray-100 flex items-center justify-between text-xs">
                        <!-- Cost info -->
                        <div>
                            <span class="text-[10px] uppercase tracking-wider text-gray-400 block font-medium">Charged</span>
                            <div class="flex items-baseline gap-1 font-mono">
                                <span class="font-bold text-gray-900 text-sm">
                                    {{ gen.status === 'succeeded' ? formatCost(gen.actual_user_cost) : (gen.status === 'failed' ? '$0.0000' : `~${formatCost(gen.estimated_user_cost)}`) }}
                                </span>
                                <span v-if="gen.status === 'succeeded'" class="text-[10px] text-gray-400">
                                    (est {{ formatCost(gen.estimated_user_cost) }})
                                </span>
                            </div>
                        </div>

                        <!-- Action: Inspect Token Audit -->
                        <button
                            v-if="gen.status === 'succeeded'"
                            type="button"
                            @click="openTokenModal(gen)"
                            class="text-xs font-semibold text-indigo-600 hover:text-indigo-800 flex items-center gap-1 py-1 px-2 rounded-lg hover:bg-indigo-50 transition"
                        >
                            <span>Tokens</span>
                            <span>→</span>
                        </button>
                        <span v-else-if="gen.status === 'failed'" class="text-[11px] text-gray-400 italic">
                            Free
                        </span>
                        <span v-else class="text-[11px] text-amber-600 font-mono animate-pulse">
                            Pending...
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pagination -->
        <div v-if="generations.links && generations.links.length > 3" class="mt-8 flex justify-center space-x-1">
            <template v-for="(link, i) in generations.links" :key="i">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    v-html="link.label"
                    :class="[
                        'px-3 py-1.5 text-xs rounded-lg font-medium transition',
                        link.active
                            ? 'bg-indigo-600 text-white font-semibold shadow-xs'
                            : 'bg-white border border-gray-200 text-gray-700 hover:bg-gray-50'
                    ]"
                />
                <span
                    v-else
                    v-html="link.label"
                    class="px-3 py-1.5 text-xs text-gray-400 rounded-lg bg-gray-50"
                />
            </template>
        </div>

        <!-- Token Breakdown Modal -->
        <TokenBreakdownModal
            :show="showBreakdownModal"
            :generation="selectedGeneration"
            @close="showBreakdownModal = false"
        />

        <!-- Image Lightbox Modal -->
        <div
            v-if="lightboxImage"
            @click="lightboxImage = null"
            class="fixed inset-0 z-50 bg-black/90 backdrop-blur-xs flex items-center justify-center p-4"
        >
            <div class="relative max-w-5xl max-h-[90vh]">
                <img :src="lightboxImage" class="max-w-full max-h-[85vh] rounded-lg shadow-2xl object-contain mx-auto" />
                <button
                    @click="lightboxImage = null"
                    class="absolute -top-10 right-0 text-white hover:text-gray-300 font-bold text-sm bg-white/20 px-3 py-1 rounded-full"
                >
                    ✕ Close
                </button>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
