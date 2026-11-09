// Live strokes (LF-Core-LiveClass § Whiteboard, Nét vẽ theo bút). The presenter
// sends a stroke while it is being drawn: `stroke.add` when the pen goes down,
// then `stroke.append` with each new run of points. The sync queue folds the
// unsent parts of one stroke into a single event, so a batch carries about one
// event per stroke and viewers see the line follow the pen.
//
// Strokes only grow, so every rule here is idempotent: a viewer may get the
// same points from a snapshot and again from an event replayed after it.

/**
 * Adds `points` that start at index `from` to a viewer's copy of a stroke,
 * skipping the part it already has. Returns false on a gap (points missing
 * between the copy and `from`): the viewer must resync.
 */
export function extendStroke(stroke, from, points) {
    const start = Number(from);
    if (! Number.isInteger(start) || start < 0 || ! Array.isArray(points)) {
        return true;
    }
    if (stroke.points.length < start) {
        return false;
    }
    const fresh = points.slice(stroke.points.length - start);
    if (fresh.length) {
        stroke.points.push(...fresh);
    }

    return true;
}

/**
 * A `stroke.add` for a stroke the viewer already has: keeps the longer copy,
 * since a snapshot may hold fewer points than an add replayed after it.
 */
export function growStroke(existing, incoming) {
    if (incoming.points.length > existing.points.length) {
        existing.points = incoming.points.slice();
    }
}

/**
 * Folds `event` into `last`, the newest unsent queue event, when both carry the
 * same stroke and the points join up. Returns the merged event, or null to
 * queue `event` on its own.
 */
export function mergeStrokeEvent(last, event) {
    if (event?.type !== 'stroke.append' || ! last || last.page !== event.page) {
        return null;
    }
    if (last.type === 'stroke.add' && last.stroke?.id === event.id && last.stroke.points.length === event.from) {
        return { ...last, stroke: { ...last.stroke, points: [...last.stroke.points, ...event.points] } };
    }
    if (last.type === 'stroke.append' && last.id === event.id && last.from + last.points.length === event.from) {
        return { ...last, points: [...last.points, ...event.points] };
    }

    return null;
}

/** True when `event` removes only the stroke that `last`, still unsent, would add: both can be dropped. */
export function cancelsUnsentStroke(last, event) {
    return event?.type === 'stroke.remove'
        && Array.isArray(event.ids) && event.ids.length === 1
        && last?.type === 'stroke.add' && last.stroke?.id === event.ids[0];
}
