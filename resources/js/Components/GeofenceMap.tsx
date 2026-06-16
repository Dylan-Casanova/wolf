import type { Geofence } from '@/types';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { useCallback, useEffect, useRef, useState } from 'react';
import { MapContainer, TileLayer, useMap } from 'react-leaflet';

interface GeofenceMapProps {
    geofence: Geofence | null;
    center: [number, number] | null;
    userPosition: [number, number] | null;
    addressPoint: [number, number] | null;
    onBoundsChange: (bounds: {
        north_lat: number;
        south_lat: number;
        east_lng: number;
        west_lng: number;
    }) => void;
}

const addressIcon = L.divIcon({
    className: '',
    html: '<div style="width:22px;height:22px;background:#ef4444;border:3px solid #fff;border-radius:50%;box-shadow:0 2px 6px rgba(0,0,0,0.4);"></div>',
    iconSize: [22, 22],
    iconAnchor: [11, 11],
});

const cornerIcon = L.divIcon({
    className: '',
    html: '<div style="width:14px;height:14px;background:#fff;border:2px solid #4f46e5;border-radius:50%;cursor:grab;"></div>',
    iconSize: [14, 14],
    iconAnchor: [7, 7],
});

function MapCenter({ center }: { center: [number, number] }) {
    const map = useMap();
    useEffect(() => {
        map.setView(center, 16);
    }, [center, map]);
    return null;
}

function AddressMarker({ position }: { position: [number, number] }) {
    const map = useMap();
    const markerRef = useRef<L.Marker | null>(null);

    useEffect(() => {
        if (markerRef.current) {
            markerRef.current.setLatLng(position);
        } else {
            markerRef.current = L.marker(position, {
                icon: addressIcon,
                interactive: false,
                keyboard: false,
            }).addTo(map);
        }
    }, [position, map]);

    useEffect(() => {
        return () => {
            if (markerRef.current) {
                map.removeLayer(markerRef.current);
                markerRef.current = null;
            }
        };
    }, [map]);

    return null;
}

function UserMarker({ position }: { position: [number, number] }) {
    const map = useMap();
    const markerRef = useRef<L.CircleMarker | null>(null);

    useEffect(() => {
        if (markerRef.current) {
            markerRef.current.setLatLng(position);
        } else {
            markerRef.current = L.circleMarker(position, {
                radius: 8,
                color: '#3b82f6',
                fillColor: '#3b82f6',
                fillOpacity: 0.9,
                weight: 3,
            }).addTo(map);
        }
    }, [position, map]);

    useEffect(() => {
        return () => {
            if (markerRef.current) {
                map.removeLayer(markerRef.current);
                markerRef.current = null;
            }
        };
    }, [map]);

    return null;
}

function EditableRectangle({
    bounds,
    onBoundsChange,
}: {
    bounds: L.LatLngBoundsExpression;
    onBoundsChange: GeofenceMapProps['onBoundsChange'];
}) {
    const map = useMap();
    const rectRef = useRef<L.Rectangle | null>(null);
    const markersRef = useRef<L.Marker[]>([]);
    const cornerIds = useRef<string[]>([]);

    const reportBounds = useCallback(
        (b: L.LatLngBounds) => {
            onBoundsChange({
                north_lat: parseFloat(b.getNorth().toFixed(7)),
                south_lat: parseFloat(b.getSouth().toFixed(7)),
                east_lng: parseFloat(b.getEast().toFixed(7)),
                west_lng: parseFloat(b.getWest().toFixed(7)),
            });
        },
        [onBoundsChange],
    );

    useEffect(() => {
        if (rectRef.current) map.removeLayer(rectRef.current);
        markersRef.current.forEach((m) => map.removeLayer(m));
        markersRef.current = [];
        cornerIds.current = [];

        const rect = L.rectangle(bounds, {
            color: '#4f46e5',
            weight: 2,
            fillOpacity: 0.15,
            interactive: true,
            bubblingMouseEvents: false,
        }).addTo(map);
        rectRef.current = rect;

        let dragStart: L.LatLng | null = null;
        let startBounds: L.LatLngBounds | null = null;

        rect.on('mousedown', (e) => {
            dragStart = e.latlng;
            startBounds = rectRef.current!.getBounds();
            map.dragging.disable();
        });

        map.on('mousemove', (e: L.LeafletMouseEvent) => {
            if (!dragStart || !startBounds) return;
            const dLat = e.latlng.lat - dragStart.lat;
            const dLng = e.latlng.lng - dragStart.lng;

            const newBounds = L.latLngBounds(
                [startBounds.getSouth() + dLat, startBounds.getWest() + dLng],
                [startBounds.getNorth() + dLat, startBounds.getEast() + dLng],
            );
            rectRef.current!.setBounds(newBounds);

            const nb = rectRef.current!.getBounds();
            const positions: Record<string, L.LatLngExpression> = {
                nw: [nb.getNorth(), nb.getWest()],
                ne: [nb.getNorth(), nb.getEast()],
                se: [nb.getSouth(), nb.getEast()],
                sw: [nb.getSouth(), nb.getWest()],
            };
            markersRef.current.forEach((m, i) => {
                m.setLatLng(positions[cornerIds.current[i]]);
            });
        });

        map.on('mouseup', () => {
            if (dragStart) {
                dragStart = null;
                startBounds = null;
                map.dragging.enable();
                reportBounds(rectRef.current!.getBounds());
            }
        });

        const b = L.latLngBounds(bounds as L.LatLngExpression[]);
        reportBounds(b);

        const corners: [string, L.LatLngExpression][] = [
            ['nw', [b.getNorth(), b.getWest()]],
            ['ne', [b.getNorth(), b.getEast()]],
            ['se', [b.getSouth(), b.getEast()]],
            ['sw', [b.getSouth(), b.getWest()]],
        ];

        corners.forEach(([id, latlng]) => {
            const marker = L.marker(latlng, {
                icon: cornerIcon,
                draggable: true,
            }).addTo(map);

            marker.on('drag', () => {
                const pos = marker.getLatLng();
                const cb = rectRef.current!.getBounds();

                let north = cb.getNorth();
                let south = cb.getSouth();
                let east = cb.getEast();
                let west = cb.getWest();

                if (id === 'nw') {
                    north = pos.lat;
                    west = pos.lng;
                } else if (id === 'ne') {
                    north = pos.lat;
                    east = pos.lng;
                } else if (id === 'se') {
                    south = pos.lat;
                    east = pos.lng;
                } else if (id === 'sw') {
                    south = pos.lat;
                    west = pos.lng;
                }

                const newBounds = L.latLngBounds([south, west], [north, east]);
                rectRef.current!.setBounds(newBounds);

                const nb = rectRef.current!.getBounds();
                const positions: Record<string, L.LatLngExpression> = {
                    nw: [nb.getNorth(), nb.getWest()],
                    ne: [nb.getNorth(), nb.getEast()],
                    se: [nb.getSouth(), nb.getEast()],
                    sw: [nb.getSouth(), nb.getWest()],
                };
                markersRef.current.forEach((m, i) => {
                    if (m !== marker) {
                        m.setLatLng(positions[cornerIds.current[i]]);
                    }
                });
            });

            marker.on('dragend', () => {
                reportBounds(rectRef.current!.getBounds());
            });

            markersRef.current.push(marker);
            cornerIds.current.push(id);
        });

        return () => {
            if (rectRef.current) map.removeLayer(rectRef.current);
            markersRef.current.forEach((m) => map.removeLayer(m));
            markersRef.current = [];
            cornerIds.current = [];
        };
    }, [bounds, map, reportBounds]);

    return null;
}

export default function GeofenceMap({
    geofence,
    center,
    userPosition,
    addressPoint,
    onBoundsChange,
}: GeofenceMapProps) {
    const defaultCenter: [number, number] = [29.4241, -98.4936];

    const mapCenter = geofence
        ? ([
              (geofence.north_lat + geofence.south_lat) / 2,
              (geofence.east_lng + geofence.west_lng) / 2,
          ] as [number, number])
        : center || defaultCenter;

    const [rectBounds, setRectBounds] = useState<L.LatLngBoundsExpression>(
        geofence
            ? [
                  [geofence.south_lat, geofence.west_lng],
                  [geofence.north_lat, geofence.east_lng],
              ]
            : center
              ? [
                    [center[0] - 0.001, center[1] - 0.0015],
                    [center[0] + 0.001, center[1] + 0.0015],
                ]
              : [
                    [defaultCenter[0] - 0.001, defaultCenter[1] - 0.0015],
                    [defaultCenter[0] + 0.001, defaultCenter[1] + 0.0015],
                ],
    );

    useEffect(() => {
        if (center && !geofence) {
            setRectBounds([
                [center[0] - 0.001, center[1] - 0.0015],
                [center[0] + 0.001, center[1] + 0.0015],
            ]);
        }
    }, [center, geofence]);

    return (
        <MapContainer
            center={mapCenter}
            zoom={16}
            className="h-[400px] w-full rounded-lg"
        >
            <TileLayer
                attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
                url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
            />
            {center && <MapCenter center={center} />}
            <EditableRectangle
                bounds={rectBounds}
                onBoundsChange={onBoundsChange}
            />
            {addressPoint && <AddressMarker position={addressPoint} />}
            {userPosition && <UserMarker position={userPosition} />}
        </MapContainer>
    );
}
