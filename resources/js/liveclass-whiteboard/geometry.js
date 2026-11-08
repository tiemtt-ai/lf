export const clamp = (value, min, max) => Math.min(max, Math.max(min, value));

/** Distance from point p to segment a–b; points are [x, y]. */
export function distanceToSegment(p, a, b) {
    const dx = b[0] - a[0];
    const dy = b[1] - a[1];
    const lengthSquared = dx * dx + dy * dy;

    if (lengthSquared === 0) {
        return Math.hypot(p[0] - a[0], p[1] - a[1]);
    }

    const t = clamp(((p[0] - a[0]) * dx + (p[1] - a[1]) * dy) / lengthSquared, 0, 1);

    return Math.hypot(p[0] - (a[0] + t * dx), p[1] - (a[1] + t * dy));
}

/** Whether an eraser circle at p with the given radius touches the stroke. */
export function strokeHit(stroke, p, radius) {
    const reach = radius + stroke.width / 2;
    const points = stroke.points;

    if (points.length === 1) {
        return Math.hypot(points[0][0] - p[0], points[0][1] - p[1]) <= reach;
    }

    for (let index = 0; index < points.length - 1; index += 1) {
        if (distanceToSegment(p, points[index], points[index + 1]) <= reach) {
            return true;
        }
    }

    return false;
}

/** Rounds to one decimal so stored strokes stay small. */
export const round1 = (value) => Math.round(value * 10) / 10;

let sequence = 0;

export function uid(prefix) {
    sequence += 1;

    return `${prefix}-${Date.now().toString(36)}-${sequence.toString(36)}-${Math.random().toString(36).slice(2, 7)}`;
}
