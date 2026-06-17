export interface NavItem {
    label: string;
    href: string;
    routeMatch: string[];
    badge: string;
    adminOnly?: boolean;
}

export const navItems: NavItem[] = [
    {
        label: 'Dashboard',
        href: '/dashboard',
        routeMatch: ['/dashboard'],
        badge: '⌂',
    },
    {
        label: 'Geofence',
        href: '/geofence',
        routeMatch: ['/geofence'],
        badge: '⊕',
    },
    {
        label: 'Devices',
        href: '/devices',
        routeMatch: ['/devices'],
        badge: '▦',
        adminOnly: true,
    },
];
