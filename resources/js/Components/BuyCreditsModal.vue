<script setup>
import { ref } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['close']);

const page = usePage();
const presetAmounts = ['5.00', '10.00', '25.00', '50.00'];

const form = useForm({
    amount: '10.00',
});

const selectPreset = (amount) => {
    form.amount = amount;
};

const submit = () => {
    form.post(route('credits.topup'), {
        preserveScroll: true,
        onSuccess: () => {
            emit('close');
        },
    });
};

const closeModal = () => {
    form.clearErrors();
    emit('close');
};
</script>

<template>
    <Modal :show="show" @close="closeModal" max-width="md">
        <div class="p-6">
            <div class="flex items-center justify-between pb-4 border-b border-gray-100">
                <div class="flex items-center space-x-2">
                    <span class="p-2 bg-indigo-50 text-indigo-600 rounded-lg text-xl">💳</span>
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Add Credit Balance</h2>
                        <p class="text-xs text-gray-500">Credits are US dollars (simulated, no real charges)</p>
                    </div>
                </div>
                <button @click="closeModal" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <div class="mt-4 bg-gray-50 p-3 rounded-lg flex items-center justify-between text-sm">
                <span class="text-gray-600">Current Balance:</span>
                <span class="font-bold text-gray-900 font-mono">
                    ${{ Number(page.props.auth.user?.credit_balance || 0).toFixed(4) }} USD
                </span>
            </div>

            <form @submit.prevent="submit" class="mt-5 space-y-4">
                <div>
                    <InputLabel value="Select quick top-up amount" />
                    <div class="grid grid-cols-4 gap-2 mt-2">
                        <button
                            v-for="preset in presetAmounts"
                            :key="preset"
                            type="button"
                            @click="selectPreset(preset)"
                            :class="[
                                'py-2 px-3 text-sm font-medium rounded-lg border transition-all text-center',
                                form.amount === preset
                                    ? 'border-indigo-600 bg-indigo-50 text-indigo-700 shadow-sm'
                                    : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50'
                            ]"
                        >
                            +${{ preset }}
                        </button>
                    </div>
                </div>

                <div>
                    <InputLabel for="custom_amount" value="Or enter custom amount ($)" />
                    <div class="relative mt-1">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-500">
                            $
                        </div>
                        <TextInput
                            id="custom_amount"
                            v-model="form.amount"
                            type="number"
                            step="0.50"
                            min="0.50"
                            max="500.00"
                            class="pl-7 block w-full"
                            placeholder="10.00"
                        />
                    </div>
                    <InputError :message="form.errors.amount" class="mt-1" />
                </div>

                <div class="pt-4 flex justify-end space-x-3 border-t border-gray-100">
                    <SecondaryButton type="button" @click="closeModal">Cancel</SecondaryButton>
                    <PrimaryButton :disabled="form.processing" class="bg-indigo-600 hover:bg-indigo-700">
                        {{ form.processing ? 'Adding...' : `Add $${form.amount} Credits` }}
                    </PrimaryButton>
                </div>
            </form>
        </div>
    </Modal>
</template>
