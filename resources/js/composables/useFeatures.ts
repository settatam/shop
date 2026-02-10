import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

export function useFeatures() {
    const page = usePage();

    const features = computed<string[]>(() => {
        return (page.props.storeFeatures as string[]) || [];
    });

    const hasFeature = (feature: string): boolean => {
        return features.value.includes(feature);
    };

    const hasAllFeatures = (requiredFeatures: string[]): boolean => {
        return requiredFeatures.every(f => features.value.includes(f));
    };

    const hasAnyFeature = (requiredFeatures: string[]): boolean => {
        return requiredFeatures.some(f => features.value.includes(f));
    };

    return {
        features,
        hasFeature,
        hasAllFeatures,
        hasAnyFeature,
    };
}
