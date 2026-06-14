import AddressSearch from '@/Components/AddressSearch';
import GeofenceMap from '@/Components/GeofenceMap';
import GeofenceToggle from '@/Components/GeofenceToggle';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Geofence } from '@/types';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { useState } from 'react';

interface GeofencePageProps {
    geofence: Geofence | null;
}

export default function Index({ geofence }: GeofencePageProps) {
    const [center, setCenter] = useState<[number, number] | null>(null);
    const [bounds, setBounds] = useState<{
        north_lat: number;
        south_lat: number;
        east_lng: number;
        west_lng: number;
    } | null>(
        geofence
            ? {
                  north_lat: geofence.north_lat,
                  south_lat: geofence.south_lat,
                  east_lng: geofence.east_lng,
                  west_lng: geofence.west_lng,
              }
            : null,
    );
    const [saving, setSaving] = useState(false);
    const [showMap, setShowMap] = useState(!!geofence);

    const handleAddressSelect = (lat: number, lng: number) => {
        setCenter([lat, lng]);
        setShowMap(true);
    };

    const handleSave = async () => {
        if (!bounds) return;
        setSaving(true);
        try {
            if (geofence) {
                await axios.put(`/geo-fences/${geofence.id}`, bounds);
            } else {
                await axios.post('/geo-fences', bounds);
            }
            router.reload();
        } catch {
            // validation error
        } finally {
            setSaving(false);
        }
    };

    const handleDelete = async () => {
        if (!geofence) return;
        await axios.delete(`/geo-fences/${geofence.id}`);
        router.reload();
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Geofence
                </h2>
            }
        >
            <Head title="Geofence" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    <div className="overflow-visible bg-white shadow-sm sm:rounded-lg">
                        <div className="p-6">
                            {!showMap && !geofence ? (
                                <div className="flex flex-col items-center gap-6">
                                    <p className="text-gray-500">
                                        No geofence configured. Search for an
                                        address to create your perimeter.
                                    </p>
                                    <div className="w-full max-w-md">
                                        <AddressSearch
                                            onSelect={handleAddressSelect}
                                        />
                                    </div>
                                </div>
                            ) : (
                                <div className="flex flex-col gap-4">
                                    {!geofence && (
                                        <AddressSearch
                                            onSelect={handleAddressSelect}
                                        />
                                    )}

                                    <GeofenceMap
                                        geofence={geofence}
                                        center={center}
                                        userPosition={null}
                                        onBoundsChange={setBounds}
                                    />

                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center gap-3">
                                            {geofence && (
                                                <GeofenceToggle
                                                    geofence={geofence}
                                                />
                                            )}
                                        </div>

                                        <div className="flex gap-2">
                                            {geofence && (
                                                <button
                                                    onClick={handleDelete}
                                                    className="rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700"
                                                >
                                                    Delete
                                                </button>
                                            )}
                                            <button
                                                onClick={handleSave}
                                                disabled={saving || !bounds}
                                                className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50"
                                            >
                                                {saving
                                                    ? 'Saving...'
                                                    : geofence
                                                      ? 'Update Perimeter'
                                                      : 'Create Perimeter'}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
