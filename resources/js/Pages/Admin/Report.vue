<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import axios from 'axios';

const props = defineProps({
    startDate: {
        type: String,
        required: true,
    },
    endDate: {
        type: String,
        required: true,
    },
    dailyStats: {
        type: Array,
        required: true,
    },
    userStats: {
        type: Array,
        required: true,
    },
    summary: {
        type: Object,
        required: true,
    },
});

const start = ref(props.startDate);
const end = ref(props.endDate);
const activeTab = ref('daily'); // 'daily' or 'users'

// Reconciliation State
const isReconciling = ref(false);
const reconciliationResult = ref(null);
const reconciliationError = ref(null);

const applyDateRange = () => {
    router.get(route('admin.report'), {
        start_date: start.value,
        end_date: end.value,
    }, { preserveState: true });
};

const runReconciliation = async () => {
    isReconciling.value = true;
    reconciliationError.value = null;

    try {
        const response = await axios.post(route('admin.reconcile'), {
            start_date: start.value,
            end_date: end.value,
        });
        reconciliationResult.value = response.data;
    } catch (e) {
        reconciliationError.value = e.response?.data?.error || e.message;
    } finally {
        isReconciling.value = false;
    }
};

const formatCost = (val) => `$${Number(val || 0).toFixed(4)}`;
</script>

<template>
    <Head title="Operator Financial Report & Reconciliation" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="text-xl">📈</span>
                        <h1 class="text-xl font-bold text-gray-900">Operator Financial & Reconciliation Report</h1>
                    </div>
                    <p class="text-xs text-gray-500 mt-0.5">
                        Audit credit spend, Pixelforge provider costs, and reconcile daily totals against Pixelforge's official usage API
                    </p>
                </div>

                <!-- Date Range Selector -->
                <div class="flex items-center gap-2 bg-white p-1.5 rounded-xl border border-gray-200 shadow-2xs">
                    <input
                        type="date"
                        v-model="start"
                        class="text-xs border-gray-300 rounded-lg py-1 px-2 focus:ring-indigo-500 focus:border-indigo-500"
                    />
                    <span class="text-xs text-gray-400">to</span>
                    <input
                        type="date"
                        v-model="end"
                        class="text-xs border-gray-300 rounded-lg py-1 px-2 focus:ring-indigo-500 focus:border-indigo-500"
                    />
                    <button
                        @click="applyDateRange"
                        type="button"
                        class="px-3 py-1 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-xs font-semibold transition"
                    >
                        Filter
                    </button>
                </div>
            </div>
        </template>

        <!-- KPI Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-5 mb-8">
            <div class="bg-white p-5 rounded-2xl border border-gray-200/80 shadow-xs">
                <span class="text-xs font-medium text-gray-500 uppercase tracking-wider block">Credit Spend (Revenue)</span>
                <span class="text-2xl font-black text-gray-900 font-mono mt-1 block">
                    {{ formatCost(summary.credit_spend) }}
                </span>
                <span class="text-[11px] text-gray-400 mt-1 block">Billed to users (+25% markup)</span>
            </div>

            <div class="bg-white p-5 rounded-2xl border border-gray-200/80 shadow-xs">
                <span class="text-xs font-medium text-gray-500 uppercase tracking-wider block">Provider Cost (COGS)</span>
                <span class="text-2xl font-black text-indigo-700 font-mono mt-1 block">
                    {{ formatCost(summary.provider_cost) }}
                </span>
                <span class="text-[11px] text-gray-400 mt-1 block">Charged by Pixelforge</span>
            </div>

            <div class="bg-white p-5 rounded-2xl border border-gray-200/80 shadow-xs">
                <span class="text-xs font-medium text-gray-500 uppercase tracking-wider block">Gross Profit</span>
                <div class="flex items-baseline gap-2 mt-1">
                    <span class="text-2xl font-black text-emerald-600 font-mono">
                        {{ formatCost(summary.profit) }}
                    </span>
                    <span class="text-xs font-bold text-emerald-700 bg-emerald-50 px-1.5 py-0.5 rounded">
                        {{ summary.margin_percent }}% margin
                    </span>
                </div>
                <span class="text-[11px] text-gray-400 mt-1 block">Net operator margin</span>
            </div>

            <div class="bg-white p-5 rounded-2xl border border-gray-200/80 shadow-xs">
                <span class="text-xs font-medium text-gray-500 uppercase tracking-wider block">Completed Generations</span>
                <span class="text-2xl font-black text-gray-900 font-mono mt-1 block">
                    {{ summary.total_generations }}
                </span>
                <span class="text-[11px] text-gray-400 mt-1 block">In selected UTC period</span>
            </div>
        </div>

        <!-- Provider Reconciliation Tool -->
        <div class="bg-white rounded-2xl border border-gray-200/80 p-6 shadow-xs mb-8 space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-4 border-b border-gray-100">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="text-base">🔍</span>
                        <h2 class="text-base font-bold text-gray-900">Pixelforge API Usage Reconciliation</h2>
                    </div>
                    <p class="text-xs text-gray-500 mt-0.5">
                        Queries <code>GET /v1/usage</code> directly from Pixelforge and compares reported costs against our recorded database ledger.
                    </p>
                </div>

                <button
                    @click="runReconciliation"
                    :disabled="isReconciling"
                    type="button"
                    class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white rounded-xl text-xs font-semibold transition flex items-center gap-2 shadow-xs"
                >
                    <span v-if="isReconciling" class="animate-spin">🔄</span>
                    <span>{{ isReconciling ? 'Querying Pixelforge...' : 'Run Live Reconciliation' }}</span>
                </button>
            </div>

            <!-- Error Banner -->
            <div v-if="reconciliationError" class="p-4 rounded-xl bg-rose-50 border border-rose-200 text-xs text-rose-800">
                <strong>Reconciliation Error:</strong> {{ reconciliationError }}
            </div>

            <!-- Result Banner -->
            <div v-if="reconciliationResult" class="space-y-4">
                <div
                    :class="[
                        'p-4 rounded-xl border flex items-center justify-between',
                        reconciliationResult.overall_matched
                            ? 'bg-emerald-50 border-emerald-200 text-emerald-900'
                            : 'bg-amber-50 border-amber-200 text-amber-900'
                    ]"
                >
                    <div class="flex items-center gap-3">
                        <span class="text-2xl">{{ reconciliationResult.overall_matched ? '✅' : '⚠️' }}</span>
                        <div>
                            <h3 class="text-sm font-bold">
                                {{ reconciliationResult.overall_matched ? 'Perfect Match: Ledger Reconciled with Pixelforge' : 'Discrepancy Detected' }}
                            </h3>
                            <p class="text-xs opacity-90 mt-0.5">
                                Pixelforge reported <strong class="font-mono">{{ formatCost(reconciliationResult.total_remote_cost) }}</strong> vs Local recorded <strong class="font-mono">{{ formatCost(reconciliationResult.total_local_cost) }}</strong>.
                                Difference: <span class="font-mono">{{ formatCost(reconciliationResult.overall_diff) }}</span>.
                            </p>
                        </div>
                    </div>

                    <span class="text-xs font-bold font-mono px-3 py-1 rounded-lg bg-white/70">
                        Diff: {{ reconciliationResult.overall_diff }}
                    </span>
                </div>

                <!-- Detailed Reconciliation Table -->
                <div class="border border-gray-200 rounded-xl overflow-hidden text-xs">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50 font-medium text-gray-600">
                            <tr>
                                <th class="px-4 py-2.5 text-left">UTC Date</th>
                                <th class="px-4 py-2.5 text-right">Pixelforge Usage Cost</th>
                                <th class="px-4 py-2.5 text-right">Local Provider Cost</th>
                                <th class="px-4 py-2.5 text-right">Variance</th>
                                <th class="px-4 py-2.5 text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 font-mono bg-white">
                            <tr v-for="item in reconciliationResult.details" :key="item.date" :class="item.matched ? '' : 'bg-rose-50/50'">
                                <td class="px-4 py-2 text-gray-900 font-sans">{{ item.date }}</td>
                                <td class="px-4 py-2 text-right text-gray-700">{{ formatCost(item.remote_cost) }}</td>
                                <td class="px-4 py-2 text-right text-indigo-700 font-bold">{{ formatCost(item.local_cost) }}</td>
                                <td class="px-4 py-2 text-right text-gray-500">{{ formatCost(item.diff) }}</td>
                                <td class="px-4 py-2 text-center">
                                    <span
                                        :class="[
                                            'px-2 py-0.5 rounded-full text-[10px] font-bold uppercase font-sans',
                                            item.matched ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'
                                        ]"
                                    >
                                        {{ item.matched ? 'Matched' : 'Delta' }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Breakdown Tabs: Daily vs Users -->
        <div class="bg-white rounded-2xl border border-gray-200/80 p-6 shadow-xs space-y-4">
            <div class="flex items-center justify-between pb-3 border-b border-gray-100">
                <div class="flex items-center gap-2">
                    <button
                        @click="activeTab = 'daily'"
                        :class="[
                            'px-3.5 py-1.5 rounded-lg text-xs font-semibold transition',
                            activeTab === 'daily'
                                ? 'bg-indigo-600 text-white shadow-xs'
                                : 'text-gray-600 hover:bg-gray-100'
                        ]"
                    >
                        Daily Performance Breakdown
                    </button>
                    <button
                        @click="activeTab = 'users'"
                        :class="[
                            'px-3.5 py-1.5 rounded-lg text-xs font-semibold transition',
                            activeTab === 'users'
                                ? 'bg-indigo-600 text-white shadow-xs'
                                : 'text-gray-600 hover:bg-gray-100'
                        ]"
                    >
                        Per-User Performance Breakdown
                    </button>
                </div>
            </div>

            <!-- Daily Table -->
            <div v-if="activeTab === 'daily'" class="overflow-x-auto text-xs">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50 font-medium text-gray-600">
                        <tr>
                            <th class="px-4 py-2.5 text-left">UTC Date</th>
                            <th class="px-4 py-2.5 text-center">Generations (Succ / Fail)</th>
                            <th class="px-4 py-2.5 text-right">Provider Cost (Pixelforge)</th>
                            <th class="px-4 py-2.5 text-right">Credit Spend (Billed)</th>
                            <th class="px-4 py-2.5 text-right">Gross Profit</th>
                            <th class="px-4 py-2.5 text-right">Margin</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 font-mono bg-white">
                        <tr v-for="day in dailyStats" :key="day.date" class="hover:bg-gray-50/70">
                            <td class="px-4 py-2.5 text-gray-900 font-sans font-medium">{{ day.date }}</td>
                            <td class="px-4 py-2.5 text-center text-gray-600 font-sans">
                                <span class="font-bold text-gray-900">{{ day.total_generations }}</span>
                                <span class="text-gray-400 text-[11px] ml-1">({{ day.succeeded }} / {{ day.failed }})</span>
                            </td>
                            <td class="px-4 py-2.5 text-right text-indigo-700">{{ formatCost(day.provider_cost) }}</td>
                            <td class="px-4 py-2.5 text-right text-gray-900 font-bold">{{ formatCost(day.credit_spend) }}</td>
                            <td class="px-4 py-2.5 text-right text-emerald-600 font-bold">{{ formatCost(day.profit) }}</td>
                            <td class="px-4 py-2.5 text-right font-sans text-gray-600">{{ day.margin_percent }}%</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Users Table -->
            <div v-if="activeTab === 'users'" class="overflow-x-auto text-xs">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50 font-medium text-gray-600">
                        <tr>
                            <th class="px-4 py-2.5 text-left">User</th>
                            <th class="px-4 py-2.5 text-right">Credit Balance</th>
                            <th class="px-4 py-2.5 text-center">Generations</th>
                            <th class="px-4 py-2.5 text-right">Provider Cost</th>
                            <th class="px-4 py-2.5 text-right">Credit Spend</th>
                            <th class="px-4 py-2.5 text-right">Gross Profit</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 font-mono bg-white">
                        <tr v-for="user in userStats" :key="user.user_id" class="hover:bg-gray-50/70">
                            <td class="px-4 py-2.5 font-sans">
                                <div class="font-semibold text-gray-900">{{ user.name }}</div>
                                <div class="text-[11px] text-gray-400">{{ user.email }}</div>
                            </td>
                            <td class="px-4 py-2.5 text-right text-gray-700">${{ Number(user.credit_balance).toFixed(4) }}</td>
                            <td class="px-4 py-2.5 text-center text-gray-600 font-sans">
                                <span class="font-bold text-gray-900">{{ user.total_generations }}</span>
                                <span class="text-gray-400 text-[11px] ml-1">({{ user.succeeded }}s / {{ user.failed }}f)</span>
                            </td>
                            <td class="px-4 py-2.5 text-right text-indigo-700">{{ formatCost(user.provider_cost) }}</td>
                            <td class="px-4 py-2.5 text-right text-gray-900 font-bold">{{ formatCost(user.credit_spend) }}</td>
                            <td class="px-4 py-2.5 text-right text-emerald-600 font-bold">{{ formatCost(user.profit) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
