import { useMapConfig } from '../api/geo';
import { GeoView } from './GeoView';
import { GeoMapLibre } from './GeoMapLibre';

/**
 * Picks the geo renderer from server config: a self-hosted vector basemap (MapLibre GL, the
 * high-scale renderer) when one is configured, otherwise the Leaflet raster view. Keeps the
 * choice in one place so the nav/shell doesn't need to know about it.
 */
export function GeoRoot() {
    const { data: config } = useMapConfig();

    if (config?.basemap?.style_url) {
        return <GeoMapLibre styleUrl={config.basemap.style_url} />;
    }
    return <GeoView />;
}
