/**
 * Scales that fit a document page into the board (LF-Core-LiveClass § Whiteboard).
 *
 * 100% is the whole page on screen: the smaller of fit-to-height and
 * fit-to-width. A landscape slide on a portrait phone is therefore fitted by
 * width, and a presenter at 100% on a wide screen is followed by the same
 * whole page on any screen.
 */
export function fitScales(screen, page, margin) {
    if (! page?.width || ! page?.height || ! screen?.width || ! screen?.height) {
        return { height: 1, width: 1, page: 1 };
    }
    const height = Math.max(0.01, (screen.height - margin * 2) / page.height);
    const width = Math.max(0.01, (screen.width - margin * 2) / page.width);

    return { height, width, page: Math.min(height, width) };
}

/** The view that shows the page whole and centred at a given scale. */
export function centredView(screen, page, scale) {
    return {
        scale,
        x: (screen.width - page.width * scale) / 2,
        y: (screen.height - page.height * scale) / 2,
    };
}
