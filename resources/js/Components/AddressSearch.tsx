import { useCallback, useRef, useState } from 'react';

interface SearchResult {
    display_name: string;
    lat: string;
    lon: string;
    boundingbox: [string, string, string, string];
}

interface AddressSearchProps {
    onSelect: (lat: number, lng: number) => void;
}

export default function AddressSearch({ onSelect }: AddressSearchProps) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<SearchResult[]>([]);
    const [loading, setLoading] = useState(false);
    const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    const search = useCallback((value: string) => {
        if (debounceRef.current) clearTimeout(debounceRef.current);
        if (value.length < 3) {
            setResults([]);
            return;
        }

        debounceRef.current = setTimeout(async () => {
            setLoading(true);
            try {
                const res = await fetch(
                    `https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(value)}&limit=5`,
                );
                const data: SearchResult[] = await res.json();
                setResults(data);
            } catch {
                setResults([]);
            } finally {
                setLoading(false);
            }
        }, 400);
    }, []);

    const handleSelect = (result: SearchResult) => {
        setQuery(result.display_name);
        setResults([]);
        onSelect(parseFloat(result.lat), parseFloat(result.lon));
    };

    return (
        <div className="relative">
            <input
                type="text"
                value={query}
                onChange={(e) => {
                    setQuery(e.target.value);
                    search(e.target.value);
                }}
                placeholder="Search address..."
                className="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
            />
            {loading && (
                <div className="absolute right-3 top-2.5 text-xs text-gray-400">
                    Searching...
                </div>
            )}
            {results.length > 0 && (
                <ul className="absolute z-[1000] mt-1 max-h-60 w-full overflow-auto rounded-md border border-gray-200 bg-white shadow-lg">
                    {results.map((r, i) => (
                        <li
                            key={i}
                            onClick={() => handleSelect(r)}
                            className="cursor-pointer px-3 py-2 text-sm text-gray-700 hover:bg-indigo-50"
                        >
                            {r.display_name}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
