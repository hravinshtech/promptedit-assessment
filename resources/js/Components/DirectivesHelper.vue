<script setup>
const props = defineProps({
    modelValue: {
        type: String,
        default: '',
    },
});

const emit = defineEmits(['update:modelValue', 'insertDirective']);

const directives = [
    {
        tag: '[succeed]',
        label: 'Succeed',
        color: 'bg-emerald-50 text-emerald-700 border-emerald-200 hover:bg-emerald-100',
        description: 'Accepted and never fails at random',
    },
    {
        tag: '[fail]',
        label: 'Fail While Processing',
        color: 'bg-rose-50 text-rose-700 border-rose-200 hover:bg-rose-100',
        description: 'Accepted, then fails during processing (generation.failed). Not billed.',
    },
    {
        tag: '[slow]',
        label: 'Slow (60-90s)',
        color: 'bg-amber-50 text-amber-700 border-amber-200 hover:bg-amber-100',
        description: 'Takes 60 to 90 seconds to process instead of 5 to 30',
    },
    {
        tag: '[duplicate]',
        label: 'Duplicate Webhook',
        color: 'bg-purple-50 text-purple-700 border-purple-200 hover:bg-purple-100',
        description: 'Every webhook event for this generation is delivered twice',
    },
    {
        tag: '[blocked]',
        label: 'Policy Violation',
        color: 'bg-red-50 text-red-700 border-red-200 hover:bg-red-100',
        description: 'Rejected at submission with 400 content_policy_violation. Not billed.',
    },
    {
        tag: '[overloaded]',
        label: '503 Overloaded',
        color: 'bg-orange-50 text-orange-700 border-orange-200 hover:bg-orange-100',
        description: 'Rejected at submission with 503 overloaded. Retried automatically.',
    },
];

const insert = (directive) => {
    emit('insertDirective', directive.tag);
};
</script>

<template>
    <div class="mt-2">
        <div class="flex items-center justify-between mb-1">
            <span class="text-xs font-medium text-gray-500 uppercase tracking-wider">
                🧪 Test Keywords (click to insert into prompt)
            </span>
        </div>
        <div class="flex flex-wrap gap-1.5">
            <button
                v-for="d in directives"
                :key="d.tag"
                type="button"
                @click="insert(d)"
                :title="d.description"
                :class="[
                    'text-xs px-2.5 py-1 rounded-full border transition-all font-mono font-medium flex items-center gap-1',
                    d.color
                ]"
            >
                <span>{{ d.tag }}</span>
                <span class="text-[11px] opacity-75 font-sans font-normal">{{ d.label }}</span>
            </button>
        </div>
    </div>
</template>
