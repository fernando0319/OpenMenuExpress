export type MapTiles = {
    url: string;
    attribution: string;
};

export type MapPoint = {
    lat: number;
    lng: number;
    accuracy: number | null;
    /** ISO time of the reading. */
    at: string;
};

export type MapMarker = {
    id: number | string;
    lat: number;
    lng: number;
    label: string;
};
