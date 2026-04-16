export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at?: string;
    is_admin: boolean;
}

export interface Device {
    id: number;
    user_id: number;
    name: string;
    device_id: string;
    type: string;
    is_online: boolean;
    last_seen_at: string | null;
    meta: Record<string, unknown> | null;
    user?: User;
}

export interface CaptureData {
    id: number;
    trigger_source: string;
    media_type: 'image' | 'video';
    media_url: string | null;
    status: 'pending' | 'success' | 'failed';
    error_message: string | null;
    captured_at: string;
    device?: { name: string };
    user?: { name: string; email: string };
}

export interface PaginatedCaptures {
    data: CaptureData[];
    links: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: User;
    };
    flash: {
        capture?: CaptureData;
        device_token?: string;
    };
};
