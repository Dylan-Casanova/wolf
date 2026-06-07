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

export interface StreamData {
    stream_id: number;
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: User;
    };
    flash: {
        device_token?: string;
    };
};
