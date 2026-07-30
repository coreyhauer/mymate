// The CSP build ships no types of its own; its runtime API is identical to maplibre-gl, so
// point the module at maplibre-gl's declarations. Used because the CSP worker loads from a
// same-origin URL rather than an inlined blob (see GeoMapLibre.tsx for why that matters here).
declare module 'maplibre-gl/dist/maplibre-gl-csp' {
    import maplibregl from 'maplibre-gl';
    export default maplibregl;
    export * from 'maplibre-gl';
}
