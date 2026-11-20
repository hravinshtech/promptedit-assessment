<script setup>
import { ref } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import NavLink from '@/Components/NavLink.vue';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';
import BuyCreditsModal from '@/Components/BuyCreditsModal.vue';
import { Link, usePage, router } from '@inertiajs/vue3';

const page = usePage();
const showingNavigationDropdown = ref(false);
const showTopupModal = ref(false);

const toggleAdminRole = () => {
    router.post(route('dev.toggle-admin'), {}, { preserveScroll: true });
};
</script>

<template>
    <div>
        <div class="min-h-screen bg-gray-50/70">
            <!-- Navigation -->
            <nav class="border-b border-gray-200/80 bg-white sticky top-0 z-40">
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div class="flex h-16 justify-between items-center">
                        <div class="flex items-center space-x-8">
                            <!-- Logo -->
                            <Link :href="route('generations.index')" class="flex items-center space-x-2">
                                <div class="w-8 h-8 rounded-lg bg-indigo-600 flex items-center justify-center text-white font-black text-lg shadow-sm shadow-indigo-200">
                                    P
                                </div>
                                <span class="text-base font-bold text-gray-900 tracking-tight">PromptEdit</span>
                                <span class="text-[10px] uppercase font-bold tracking-wider px-1.5 py-0.5 rounded bg-indigo-50 text-indigo-700 border border-indigo-100">
                                    Pixelforge
                                </span>
                            </Link>

                            <!-- Navigation Links -->
                            <div class="hidden space-x-6 sm:flex">
                                <NavLink
                                    :href="route('generations.index')"
                                    :active="route().current('generations.index')"
                                >
                                    Generations History
                                </NavLink>
                                <NavLink
                                    :href="route('generations.create')"
                                    :active="route().current('generations.create')"
                                >
                                    + Generate Image
                                </NavLink>
                                <NavLink
                                    v-if="$page.props.auth.user.is_admin"
                                    :href="route('admin.report')"
                                    :active="route().current('admin.report')"
                                >
                                    Admin Report
                                </NavLink>
                            </div>
                        </div>

                        <!-- Right Actions -->
                        <div class="hidden sm:flex sm:items-center sm:space-x-4">
                            <!-- Dev Admin Switcher -->
                            <button
                                @click="toggleAdminRole"
                                type="button"
                                :class="[
                                    'text-xs px-2.5 py-1 rounded-md border font-medium transition-colors flex items-center gap-1',
                                    $page.props.auth.user.is_admin
                                        ? 'bg-purple-50 text-purple-700 border-purple-200 hover:bg-purple-100'
                                        : 'bg-gray-50 text-gray-600 border-gray-200 hover:bg-gray-100'
                                ]"
                                title="Toggle Admin status for testing the Operator Report stretch goal"
                            >
                                <span>{{ $page.props.auth.user.is_admin ? '👑 Admin' : '👤 User' }}</span>
                                <span class="text-[10px] text-gray-400 underline">switch</span>
                            </button>

                            <!-- Credit Balance Badge -->
                            <div class="flex items-center rounded-lg border border-gray-200 bg-gray-50 px-3 py-1.5 shadow-xs">
                                <div class="mr-2">
                                    <span class="text-[10px] uppercase tracking-wider text-gray-500 font-medium block leading-none">Balance</span>
                                    <span class="text-sm font-bold font-mono text-gray-900 leading-tight">
                                        ${{ Number($page.props.auth.user.credit_balance || 0).toFixed(4) }}
                                    </span>
                                </div>
                                <button
                                    @click="showTopupModal = true"
                                    type="button"
                                    class="ml-1 inline-flex items-center rounded bg-indigo-600 px-2.5 py-1 text-xs font-semibold text-white shadow-xs hover:bg-indigo-700 transition"
                                >
                                    + Buy Credits
                                </button>
                            </div>

                            <!-- Settings Dropdown -->
                            <div class="relative">
                                <Dropdown align="right" width="48">
                                    <template #trigger>
                                        <button
                                            type="button"
                                            class="inline-flex items-center rounded-md border border-transparent bg-white px-3 py-2 text-sm font-medium text-gray-600 hover:text-gray-900"
                                        >
                                            {{ $page.props.auth.user.name }}
                                            <svg class="ms-1.5 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                            </svg>
                                        </button>
                                    </template>

                                    <template #content>
                                        <div class="px-4 py-2 border-b border-gray-100 text-xs text-gray-500">
                                            Signed in as <span class="font-medium text-gray-800">{{ $page.props.auth.user.email }}</span>
                                        </div>
                                        <DropdownLink :href="route('profile.edit')">Profile</DropdownLink>
                                        <DropdownLink :href="route('logout')" method="post" as="button">Log Out</DropdownLink>
                                    </template>
                                </Dropdown>
                            </div>
                        </div>

                        <!-- Mobile Hamburger -->
                        <div class="-me-2 flex items-center sm:hidden">
                            <button
                                @click="showingNavigationDropdown = !showingNavigationDropdown"
                                class="inline-flex items-center justify-center rounded-md p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-500"
                            >
                                <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                                    <path
                                        :class="{ hidden: showingNavigationDropdown, 'inline-flex': !showingNavigationDropdown }"
                                        stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"
                                    />
                                    <path
                                        :class="{ hidden: !showingNavigationDropdown, 'inline-flex': showingNavigationDropdown }"
                                        stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"
                                    />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Responsive Mobile Menu -->
                <div :class="{ block: showingNavigationDropdown, hidden: !showingNavigationDropdown }" class="sm:hidden border-t border-gray-200">
                    <div class="space-y-1 pb-3 pt-2">
                        <ResponsiveNavLink :href="route('generations.index')" :active="route().current('generations.index')">
                            Generations History
                        </ResponsiveNavLink>
                        <ResponsiveNavLink :href="route('generations.create')" :active="route().current('generations.create')">
                            + Generate Image
                        </ResponsiveNavLink>
                        <ResponsiveNavLink v-if="$page.props.auth.user.is_admin" :href="route('admin.report')" :active="route().current('admin.report')">
                            Admin Report
                        </ResponsiveNavLink>
                    </div>

                    <div class="border-t border-gray-200 p-4 bg-gray-50 flex items-center justify-between">
                        <div>
                            <span class="text-xs text-gray-500 block">Balance</span>
                            <span class="font-bold text-gray-900 font-mono">
                                ${{ Number($page.props.auth.user.credit_balance || 0).toFixed(4) }} USD
                            </span>
                        </div>
                        <button
                            @click="showTopupModal = true"
                            class="rounded bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white"
                        >
                            + Buy Credits
                        </button>
                    </div>

                    <div class="border-t border-gray-200 pb-1 pt-2">
                        <ResponsiveNavLink :href="route('profile.edit')">Profile</ResponsiveNavLink>
                        <ResponsiveNavLink :href="route('logout')" method="post" as="button">Log Out</ResponsiveNavLink>
                    </div>
                </div>
            </nav>

            <!-- Flash Banners -->
            <div v-if="$page.props.flash?.success" class="bg-emerald-600 text-white px-4 py-2 text-center text-sm font-medium shadow-sm flex items-center justify-center gap-2">
                <span>✓</span>
                <span>{{ $page.props.flash.success }}</span>
            </div>
            <div v-if="$page.props.flash?.error" class="bg-rose-600 text-white px-4 py-2 text-center text-sm font-medium shadow-sm flex items-center justify-center gap-2">
                <span>⚠️</span>
                <span>{{ $page.props.flash.error }}</span>
            </div>

            <!-- Page Heading -->
            <header class="bg-white border-b border-gray-200/60" v-if="$slots.header">
                <div class="mx-auto max-w-7xl px-4 py-4 sm:px-6 lg:px-8">
                    <slot name="header" />
                </div>
            </header>

            <!-- Main Content -->
            <main class="py-8">
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <slot />
                </div>
            </main>
        </div>

        <!-- Buy Credits Modal -->
        <BuyCreditsModal :show="showTopupModal" @close="showTopupModal = false" />
    </div>
</template>
