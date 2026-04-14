export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at?: string;
}

export interface CaptureData {
    id: number;
    trigger_source: string;
    media_type: 'image' | 'video';
    media_url: string | null;
    status: 'pending' | 'success' | 'failed';
    error_message: string | null;
    captured_at: string;
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: User;
    };
    flash: {
        capture?: CaptureData;
    };
};
