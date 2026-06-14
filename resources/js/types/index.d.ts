export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at?: string;
    is_admin: boolean;
}

export interface Device {
    id: number;
    user_id: number | null;
    name: string;
    device_id: string;
    type: 'esp32_cam' | 'esp8266';
    is_online: boolean;
    last_seen_at: string | null;
    meta: Record<string, unknown> | null;
    user?: User;
}

export interface ScheduledTrigger {
    id: number;
    scheduled_at: string;
    status: 'pending' | 'fired' | 'cancelled';
    origin_lat: number;
    origin_lng: number;
    origin_distance_meters: number;
}

export interface Geofence {
    id: number;
    user_id: number;
    north_lat: number;
    south_lat: number;
    east_lng: number;
    west_lng: number;
    is_active: boolean;
    pending_scheduled_trigger: ScheduledTrigger | null;
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
    server_now?: string;
};
