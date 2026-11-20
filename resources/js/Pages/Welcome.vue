<script setup>
import { Head, Link, router } from '@inertiajs/vue3';

defineProps({
    canLogin: Boolean,
    canRegister: Boolean,
});

const quickLogin = (email) => {
    router.post(route('login'), {
        email: email,
        password: 'password',
    });
};
</script>

<template>
    <Head title="PromptEdit — Pixelforge AI Image Studio" />

    <div class="min-h-screen bg-gradient-to-b from-gray-900 via-gray-950 to-black text-white selection:bg-indigo-500 selection:text-white">
        <!-- Top Nav -->
        <header class="max-w-7xl mx-auto px-6 py-6 flex items-center justify-between border-b border-white/10">
            <div class="flex items-center space-x-3">
                <div class="w-9 h-9 rounded-xl bg-indigo-600 flex items-center justify-center font-black text-xl shadow-lg shadow-indigo-500/30">
                    P
                </div>
                <div>
                    <span class="text-lg font-bold tracking-tight block">PromptEdit</span>
                    <span class="text-[10px] text-gray-400 font-mono tracking-wider uppercase">Senior Product Engineer Assessment</span>
                </div>
            </div>

            <nav class="flex items-center gap-3">
                <template v-if="$page.props.auth?.user">
                    <Link
                        :href="route('generations.index')"
                        class="px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-xs font-semibold shadow-sm transition"
                    >
                        Go to Studio →
                    </Link>
                </template>
                <template v-else>
                    <Link
                        :href="route('login')"
                        class="px-4 py-2 rounded-xl text-xs font-medium text-gray-300 hover:text-white transition"
                    >
                        Sign In
                    </Link>
                    <Link
                        :href="route('register')"
                        class="px-4 py-2 rounded-xl bg-white/10 hover:bg-white/20 text-xs font-semibold transition border border-white/10"
                    >
                        Get Started
                    </Link>
                </template>
            </nav>
        </header>

        <!-- Hero Section -->
        <main class="max-w-6xl mx-auto px-6 py-16 text-center space-y-6">
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-indigo-500/10 border border-indigo-500/30 text-indigo-400 text-xs font-medium">
                <span class="w-2 h-2 rounded-full bg-indigo-400 animate-pulse"></span>
                <span>Pixelforge AI Provider Integration</span>
            </div>

            <h1 class="text-4xl sm:text-6xl font-extrabold tracking-tight max-w-3xl mx-auto leading-tight">
                AI Image Generations with <span class="bg-gradient-to-r from-indigo-400 via-purple-300 to-pink-400 bg-clip-text text-transparent">Exact Token Billing</span>
            </h1>

            <p class="text-gray-400 text-sm sm:text-base max-w-2xl mx-auto leading-relaxed">
                Asynchronous image generations, transparent up-front cost estimations, 25% markup with sub-cent <code class="text-indigo-300 font-mono">bcmath</code> precision, idempotent HMAC-SHA256 webhooks, and operator financial reconciliation.
            </p>

            <!-- Quick Demo Login Cards -->
            <div class="pt-8 max-w-2xl mx-auto">
                <h3 class="text-xs uppercase tracking-wider text-gray-400 font-semibold mb-3">
                    ⚡ Quick Login for Assessment Review
                </h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-left">
                    <!-- Admin Account Card -->
                    <div class="p-5 rounded-2xl bg-white/5 border border-white/10 hover:border-indigo-500/50 transition group flex flex-col justify-between space-y-4">
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <span class="text-sm font-bold text-white flex items-center gap-1.5">
                                    <span>👑</span> Operator / Admin
                                </span>
                                <span class="text-[10px] bg-purple-500/20 text-purple-300 px-2 py-0.5 rounded-full font-semibold border border-purple-500/30">
                                    Full Access
                                </span>
                            </div>
                            <p class="text-xs text-gray-400 mt-1">
                                Includes $50.00 test balance and Operator Reconciliation Report matching against <code class="text-gray-300">GET /v1/usage</code>.
                            </p>
                            <div class="mt-3 font-mono text-[11px] text-gray-400 bg-black/40 p-2 rounded-lg border border-white/5 space-y-0.5">
                                <div>Email: <span class="text-gray-200">admin@promptedit.com</span></div>
                                <div>Pass: <span class="text-gray-200">password</span></div>
                            </div>
                        </div>

                        <button
                            @click="quickLogin('admin@promptedit.com')"
                            type="button"
                            class="w-full py-2.5 px-4 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-xs font-bold text-white transition shadow-sm text-center"
                        >
                            Log In as Admin →
                        </button>
                    </div>

                    <!-- Standard User Card -->
                    <div class="p-5 rounded-2xl bg-white/5 border border-white/10 hover:border-indigo-500/50 transition group flex flex-col justify-between space-y-4">
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <span class="text-sm font-bold text-white flex items-center gap-1.5">
                                    <span>👤</span> Standard User
                                </span>
                                <span class="text-[10px] bg-emerald-500/20 text-emerald-300 px-2 py-0.5 rounded-full font-semibold border border-emerald-500/30">
                                    Designer
                                </span>
                            </div>
                            <p class="text-xs text-gray-400 mt-1">
                                Generates images, tests prompt directives, tops up credits, and views token breakdown audits.
                            </p>
                            <div class="mt-3 font-mono text-[11px] text-gray-400 bg-black/40 p-2 rounded-lg border border-white/5 space-y-0.5">
                                <div>Email: <span class="text-gray-200">demo@promptedit.com</span></div>
                                <div>Pass: <span class="text-gray-200">password</span></div>
                            </div>
                        </div>

                        <button
                            @click="quickLogin('demo@promptedit.com')"
                            type="button"
                            class="w-full py-2.5 px-4 rounded-xl bg-white/10 hover:bg-white/20 text-xs font-bold text-white transition border border-white/10 text-center"
                        >
                            Log In as Demo User →
                        </button>
                    </div>
                </div>
            </div>

            <!-- Features Grid -->
            <div class="pt-16 grid grid-cols-1 md:grid-cols-3 gap-6 text-left max-w-5xl mx-auto">
                <div class="p-6 rounded-2xl bg-white/[0.03] border border-white/10 space-y-2">
                    <span class="text-2xl">💰</span>
                    <h3 class="text-sm font-bold text-white">Exact Token Pricing (+25%)</h3>
                    <p class="text-xs text-gray-400 leading-relaxed">
                        Computes exact fractional cent costs using <code class="text-indigo-300">bcmath</code> with 9 decimal places. Up-front range estimates before submission; failed generations cost $0.
                    </p>
                </div>

                <div class="p-6 rounded-2xl bg-white/[0.03] border border-white/10 space-y-2">
                    <span class="text-2xl">🛡️</span>
                    <h3 class="text-sm font-bold text-white">Idempotent Webhooks</h3>
                    <p class="text-xs text-gray-400 leading-relaxed">
                        HMAC-SHA256 signature verification, 300s replay window, duplicate delivery deduplication, and a state machine resilient to out-of-order events.
                    </p>
                </div>

                <div class="p-6 rounded-2xl bg-white/[0.03] border border-white/10 space-y-2">
                    <span class="text-2xl">📊</span>
                    <h3 class="text-sm font-bold text-white">Live Reconcile with GET /v1/usage</h3>
                    <p class="text-xs text-gray-400 leading-relaxed">
                        Operator dashboard comparing recorded local provider costs against Pixelforge's official usage report, auditing gross profit and margins.
                    </p>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <footer class="border-t border-white/10 py-8 text-center text-xs text-gray-500">
            PromptEdit Senior Product Engineer Take-Home Assessment — Built with Laravel, Vue 3, Inertia.js, and Tailwind CSS.
        </footer>
    </div>
</template>
