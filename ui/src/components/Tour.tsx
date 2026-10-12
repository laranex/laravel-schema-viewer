import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState, type ReactNode } from 'react';

export type TourStep = {
    // A CSS selector, a function returning the element to spotlight, or a list of them where the first one on screen wins.
    // None: a centered card.
    target?: Target | Target[];
    title: string;
    body: ReactNode;
    // The step explains something inside the ⋯ menu, which the tour opens for it: on every screen, or only on phones
    // (where the toolbar's controls move into the menu).
    menu?: 'always' | 'phone';
    // A toolbar menu the tour opens for this step on wider screens, so the step can show what is inside it.
    opens?: 'download';
};

type Target = string | (() => Element | null);

type Rect = { x: number; y: number; width: number; height: number };

const PAD = 8;
const CARD_WIDTH = 340;
const GAP = 14;

// On screen: rendered, and at least partly inside the window (a phone's closed sheet sits below it).
const onScreen = (el: Element | null): el is Element => {
    if (!el || el.getClientRects().length === 0) {
        return false;
    }
    const r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0 && r.bottom > 0 && r.right > 0 && r.top < window.innerHeight && r.left < window.innerWidth;
};

const find = (target: TourStep['target']): Element | null => {
    for (const t of target === undefined ? [] : Array.isArray(target) ? target : [target]) {
        const el = typeof t === 'string' ? document.querySelector(t) : t();
        if (onScreen(el)) {
            return el;
        }
    }
    return null;
};

// The part of the element you can actually see: clipped by every scrolling or clipping container around it and by the
// window, plus some room around it.
const rectOf = (el: Element): Rect => {
    const r = el.getBoundingClientRect();
    let left = r.left;
    let top = r.top;
    let right = r.right;
    let bottom = r.bottom;
    for (let parent = el.parentElement; parent; parent = parent.parentElement) {
        const style = getComputedStyle(parent);
        if (style.overflowX !== 'visible' || style.overflowY !== 'visible') {
            const p = parent.getBoundingClientRect();
            left = Math.max(left, p.left);
            top = Math.max(top, p.top);
            right = Math.min(right, p.right);
            bottom = Math.min(bottom, p.bottom);
        }
    }
    left = Math.max(4, left - PAD);
    top = Math.max(4, top - PAD);
    right = Math.min(window.innerWidth - 4, right + PAD);
    bottom = Math.min(window.innerHeight - 4, bottom + PAD);
    return { x: left, y: top, width: Math.max(0, right - left), height: Math.max(0, bottom - top) };
};

// Rendered, though maybe scrolled out of view inside a menu: what reveal() brings into view.
const findRendered = (target: TourStep['target']): Element | null => {
    for (const t of target === undefined ? [] : Array.isArray(target) ? target : [target]) {
        const el = typeof t === 'string' ? document.querySelector(t) : t();
        if (el && el.getClientRects().length > 0) {
            return el;
        }
    }
    return null;
};

// Inside a menu or panel that scrolls, bring the element into view. Never inside the diagram: its container's
// overflow is hidden, and scrolling it shifts the drawing.
const reveal = (el: Element | null) => {
    if (el && !el.closest('.react-flow')) {
        el.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    }
};

// Where the card goes: below, above, right or left of the spotlight, whichever fits first; clamped to the window.
function placeCard(rect: Rect | null, card: { width: number; height: number }) {
    const vw = window.innerWidth;
    const vh = window.innerHeight;
    // Phones: dock the card to the top or bottom edge, away from the spotlight.
    if (vw < 600) {
        const spotlightLow = rect !== null && rect.y + rect.height / 2 > vh / 2;
        return { left: 12, top: spotlightLow ? 12 : vh - card.height - 12 };
    }
    if (!rect) {
        return { left: (vw - card.width) / 2, top: Math.max(16, (vh - card.height) / 2) };
    }
    const clampX = (x: number) => Math.min(Math.max(16, x), vw - card.width - 16);
    const clampY = (y: number) => Math.min(Math.max(16, y), vh - card.height - 16);
    const centerX = rect.x + rect.width / 2 - card.width / 2;
    const centerY = rect.y + rect.height / 2 - card.height / 2;
    if (rect.y + rect.height + GAP + card.height <= vh - 16) {
        return { left: clampX(centerX), top: rect.y + rect.height + GAP };
    }
    if (rect.y - GAP - card.height >= 16) {
        return { left: clampX(centerX), top: rect.y - GAP - card.height };
    }
    if (rect.x + rect.width + GAP + card.width <= vw - 16) {
        return { left: rect.x + rect.width + GAP, top: clampY(centerY) };
    }
    if (rect.x - GAP - card.width >= 16) {
        return { left: rect.x - GAP - card.width, top: clampY(centerY) };
    }
    // Nothing fits beside it (a large area such as the diagram): sit inside it, at the bottom.
    return { left: clampX(centerX), top: clampY(rect.y + rect.height - card.height - 24) };
}

// A guided tour: dims the page, spotlights one section at a time and explains it.
export function Tour({ steps, open, onClose, onStep, menuSteps }: { steps: TourStep[]; open: boolean; onClose: () => void; onStep?: (step: TourStep | null) => void; menuSteps: boolean }) {
    // Menu steps count when the menu exists (phones): their targets only appear once the tour opens it.
    // Steps that pick their element when shown (a table, a label) always count, so the total never depends on timing.
    const available = useMemo(
        () => (open ? steps.filter((s) => !s.target || typeof s.target === 'function' || find(s.target) || s.menu === 'always' || (s.menu === 'phone' && menuSteps) || (s.opens !== undefined && !menuSteps)) : []),
        [open, steps, menuSteps],
    );
    const [index, setIndex] = useState(0);
    const [rect, setRect] = useState<Rect | null>(null);
    const [card, setCard] = useState({ width: CARD_WIDTH, height: 180 });
    const cardRef = useRef<HTMLDivElement>(null);
    const nextRef = useRef<HTMLButtonElement>(null);
    const step = available[index];

    useEffect(() => {
        if (open) {
            setIndex(0);
        }
    }, [open]);

    // Follow the spotlighted element as the page scrolls, resizes or the diagram moves.
    useEffect(() => {
        if (!open || !step) {
            return;
        }
        let frame = 0;
        const update = () => {
            const el = find(step.target);
            const next = el ? rectOf(el) : null;
            setRect((prev) => (prev && next && Math.abs(prev.x - next.x) + Math.abs(prev.y - next.y) + Math.abs(prev.width - next.width) + Math.abs(prev.height - next.height) < 1 ? prev : next));
            frame = window.requestAnimationFrame(update);
        };
        // A menu opened for this step renders a frame later, so look for the element again briefly.
        let tries = 0;
        const revealSoon = () => {
            const el = find(step.target) ?? findRendered(step.target);
            if (el) {
                reveal(el);
            } else if (tries++ < 10) {
                window.setTimeout(revealSoon, 50);
            }
        };
        revealSoon();
        update();
        return () => window.cancelAnimationFrame(frame);
    }, [open, step]);

    useLayoutEffect(() => {
        if (cardRef.current) {
            const r = cardRef.current.getBoundingClientRect();
            setCard((prev) => (Math.abs(prev.height - r.height) > 1 || Math.abs(prev.width - r.width) > 1 ? { width: r.width, height: r.height } : prev));
        }
    });

    useEffect(() => {
        nextRef.current?.focus();
    }, [index, open]);

    useEffect(() => {
        onStep?.(open ? (step ?? null) : null);
    }, [open, step, onStep]);

    const next = useCallback(() => (index >= available.length - 1 ? onClose() : setIndex(index + 1)), [index, available.length, onClose]);
    const back = useCallback(() => setIndex(Math.max(0, index - 1)), [index]);

    useEffect(() => {
        if (!open) {
            return;
        }
        const onKey = (e: KeyboardEvent) => {
            const actions: Record<string, () => void> = { ArrowRight: next, ArrowLeft: back, Escape: onClose };
            const action = actions[e.key];
            if (action) {
                e.preventDefault();
                e.stopPropagation();
                action();
            }
        };
        window.addEventListener('keydown', onKey, true);
        return () => window.removeEventListener('keydown', onKey, true);
    }, [open, next, back, onClose]);

    if (!open || !step) {
        return null;
    }

    const position = placeCard(rect, card);
    const last = index === available.length - 1;

    return (
        <div className="tour" role="presentation">
            <svg className="tour__shade" aria-hidden="true" width="100%" height="100%">
                <defs>
                    <mask id="tour-hole">
                        <rect width="100%" height="100%" fill="white" />
                        {rect && <rect x={rect.x} y={rect.y} width={rect.width} height={rect.height} rx="12" fill="black" />}
                    </mask>
                </defs>
                <rect width="100%" height="100%" mask="url(#tour-hole)" />
            </svg>
            {rect && <div className="tour__ring" style={{ left: rect.x, top: rect.y, width: rect.width, height: rect.height }} />}
            <div ref={cardRef} className="tour__card" role="dialog" aria-modal="true" aria-labelledby="tour-title" style={{ left: position.left, top: position.top, width: window.innerWidth < 600 ? window.innerWidth - 24 : CARD_WIDTH }}>
                <div className="tour__head">
                    <span className="tour__count">
                        {index + 1} of {available.length}
                    </span>
                    <button type="button" className="button button--icon button--ghost" aria-label="End the tour" onClick={onClose}>
                        <svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true" className="ui-icon">
                            <path d="M4 4 L12 12 M12 4 L4 12" />
                        </svg>
                    </button>
                </div>
                <h2 id="tour-title" className="tour__title">
                    {step.title}
                </h2>
                <div className="tour__body">{step.body}</div>
                <div className="tour__progress" aria-hidden="true">
                    {available.map((s, i) => (
                        <span key={s.title} className={i === index ? 'is-on' : i < index ? 'is-done' : undefined} />
                    ))}
                </div>
                <div className="tour__actions">
                    {index > 0 ? (
                        <button type="button" className="button" onClick={back}>
                            Back
                        </button>
                    ) : (
                        <button type="button" className="button button--quiet" onClick={onClose}>
                            Skip
                        </button>
                    )}
                    <button ref={nextRef} type="button" className="button button--primary" onClick={next}>
                        {last ? 'Done' : 'Next'}
                    </button>
                </div>
            </div>
        </div>
    );
}
