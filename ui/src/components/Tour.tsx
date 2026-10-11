import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState, type ReactNode } from 'react';

export type TourStep = {
    // A CSS selector or a function returning the element to spotlight. None: a centered card.
    target?: string | (() => Element | null);
    title: string;
    body: ReactNode;
};

type Rect = { x: number; y: number; width: number; height: number };

const PAD = 8;
const CARD_WIDTH = 340;
const GAP = 14;

const find = (target: TourStep['target']): Element | null => {
    if (!target) {
        return null;
    }
    const el = typeof target === 'string' ? document.querySelector(target) : target();
    return el && el.getClientRects().length > 0 ? el : null;
};

const rectOf = (el: Element): Rect => {
    const r = el.getBoundingClientRect();
    return { x: r.left - PAD, y: r.top - PAD, width: r.width + PAD * 2, height: r.height + PAD * 2 };
};

// Where the card goes: below, above, right or left of the spotlight, whichever fits first; clamped to the window.
function placeCard(rect: Rect | null, card: { width: number; height: number }) {
    const vw = window.innerWidth;
    const vh = window.innerHeight;
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
export function Tour({ steps, open, onClose }: { steps: TourStep[]; open: boolean; onClose: () => void }) {
    const available = useMemo(() => (open ? steps.filter((s) => !s.target || find(s.target)) : []), [open, steps]);
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
        const el = find(step.target);
        el?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
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
            <div ref={cardRef} className="tour__card" role="dialog" aria-modal="true" aria-labelledby="tour-title" style={{ left: position.left, top: position.top, width: Math.min(CARD_WIDTH, window.innerWidth - 32) }}>
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
