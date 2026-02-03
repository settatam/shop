import { toast } from 'sonner';

export function useToast() {
    return {
        success: (message: string, options?: { description?: string }) => {
            toast.success(message, options);
        },
        error: (message: string, options?: { description?: string }) => {
            toast.error(message, options);
        },
        warning: (message: string, options?: { description?: string }) => {
            toast.warning(message, options);
        },
        info: (message: string, options?: { description?: string }) => {
            toast.info(message, options);
        },
        message: (message: string, options?: { description?: string }) => {
            toast.message(message, options);
        },
        promise: <T>(
            promise: Promise<T>,
            options: {
                loading: string;
                success: string | ((data: T) => string);
                error: string | ((error: Error) => string);
            }
        ) => {
            return toast.promise(promise, options);
        },
        dismiss: (toastId?: string | number) => {
            toast.dismiss(toastId);
        },
    };
}
