import * as DialogPrimitive from '@radix-ui/react-dialog';
import * as MenuPrimitive from '@radix-ui/react-dropdown-menu';
import * as SelectPrimitive from '@radix-ui/react-select';
import * as TabsPrimitive from '@radix-ui/react-tabs';
import * as TooltipPrimitive from '@radix-ui/react-tooltip';
import { cloneElement, useState, type PointerEvent as ReactPointerEvent, type ReactElement, type ReactNode } from 'react';

// The viewer's own controls, built on Radix primitives and styled with the page's tokens.

const Chevron = () => (
    <svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true" className="ui-icon">
        <path d="M4 6 L8 10 L12 6" />
    </svg>
);

const Check = () => (
    <svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true" className="ui-icon">
        <path d="M3.5 8.5 L6.5 11.5 L12.5 4.5" />
    </svg>
);

export type SelectOption = { value: string; label: ReactNode; hint?: ReactNode };

export function Select({
    id,
    value,
    onChange,
    options,
    label,
    compactLabel,
    hint,
}: {
    id: string;
    value: string;
    onChange: (value: string) => void;
    options: SelectOption[];
    label: string;
    compactLabel?: ReactNode;
    hint?: ReactNode;
}) {
    const current = options.find((o) => o.value === value);
    return (
        <SelectPrimitive.Root value={value} onValueChange={onChange}>
            <SelectPrimitive.Trigger id={id} className="ui-select" aria-label={label}>
                <span className="ui-select__value">{compactLabel ?? current?.label}</span>
                {hint}
                <SelectPrimitive.Icon className="ui-select__icon">
                    <Chevron />
                </SelectPrimitive.Icon>
            </SelectPrimitive.Trigger>
            <SelectPrimitive.Portal>
                <SelectPrimitive.Content className="ui-popover ui-select__content" position="popper" sideOffset={6} align="start">
                    <SelectPrimitive.Viewport>
                        {options.map((o) => (
                            <SelectPrimitive.Item key={o.value} value={o.value} className="ui-item">
                                <span className="ui-item__check">
                                    <SelectPrimitive.ItemIndicator>
                                        <Check />
                                    </SelectPrimitive.ItemIndicator>
                                </span>
                                <span className="ui-item__body">
                                    <SelectPrimitive.ItemText>{o.label}</SelectPrimitive.ItemText>
                                    {o.hint && <span className="ui-item__hint">{o.hint}</span>}
                                </span>
                            </SelectPrimitive.Item>
                        ))}
                    </SelectPrimitive.Viewport>
                </SelectPrimitive.Content>
            </SelectPrimitive.Portal>
        </SelectPrimitive.Root>
    );
}

export function TooltipProvider({ children }: { children: ReactNode }) {
    return (
        <TooltipPrimitive.Provider delayDuration={250} skipDelayDuration={150}>
            {children}
        </TooltipPrimitive.Provider>
    );
}

// Wraps one focusable or hoverable element; the element must accept a ref (a DOM element or forwardRef component).
export function Tooltip({ content, children, side = 'top' }: { content: ReactNode; children: ReactNode; side?: 'top' | 'right' | 'bottom' | 'left' }) {
    return (
        <TooltipPrimitive.Root>
            <TooltipPrimitive.Trigger asChild>{children}</TooltipPrimitive.Trigger>
            <TooltipPrimitive.Portal>
                <TooltipPrimitive.Content className="ui-tooltip" side={side} sideOffset={6} collisionPadding={12}>
                    {content}
                    <TooltipPrimitive.Arrow className="ui-tooltip__arrow" width={10} height={5} />
                </TooltipPrimitive.Content>
            </TooltipPrimitive.Portal>
        </TooltipPrimitive.Root>
    );
}

// A Tooltip that mounts on the first hover: a diagram has thousands of rows and labels with tooltips, and a mounted
// tooltip costs far more than the element it describes. Radix opens on pointer move, so the first move still opens it.
export function LazyTooltip(props: { content: ReactNode; children: ReactElement<{ onPointerEnter?: (e: ReactPointerEvent) => void }>; side?: 'top' | 'right' | 'bottom' | 'left' }) {
    const [armed, setArmed] = useState(false);
    if (armed) {
        return <Tooltip {...props} />;
    }
    return cloneElement(props.children, {
        onPointerEnter: (e: ReactPointerEvent) => {
            props.children.props.onPointerEnter?.(e);
            setArmed(true);
        },
    });
}

export type MenuItem = { label: string; hint?: string; shortcut?: string; onSelect: () => void; tour?: string };

export type MenuGroup = { label?: ReactNode; items: MenuItem[]; tour?: string };

export type MenuChoice = { label: string; value: string; options: SelectOption[]; onChange: (value: string) => void; hint?: ReactNode };

export type MenuToggle = { label: string; checked: boolean; onChange: () => void; icon?: ReactNode; hotkey?: string };

// A labeled single choice: the selected option is checked.
function ChoiceGroup({ choice, tour, separator = true }: { choice: MenuChoice; tour?: string; separator?: boolean }) {
    return (
        <MenuPrimitive.Group data-tour={tour}>
            <MenuPrimitive.Label className="ui-menu__label">
                {choice.label}
                {choice.hint}
            </MenuPrimitive.Label>
            <MenuPrimitive.RadioGroup value={choice.value} onValueChange={choice.onChange}>
                {choice.options.map((o) => (
                    <MenuPrimitive.RadioItem key={o.value} value={o.value} className="ui-item ui-item--choice">
                        <span className="ui-item__check">
                            <MenuPrimitive.ItemIndicator>
                                <Check />
                            </MenuPrimitive.ItemIndicator>
                        </span>
                        <span className="ui-item__body">
                            {o.label}
                            {o.hint && <span className="ui-item__hint">{o.hint}</span>}
                        </span>
                    </MenuPrimitive.RadioItem>
                ))}
            </MenuPrimitive.RadioGroup>
            {separator && <MenuPrimitive.Separator className="ui-menu__separator" />}
        </MenuPrimitive.Group>
    );
}

export function Menu({
    label,
    trigger,
    items,
    choice,
    toggles,
    open,
    onOpenChange,
    triggerClass = 'button button--icon',
    itemsLabel,
    groups = [],
    afterToggles,
    tour,
}: {
    // Marks the open menu for the guided tour.
    tour?: string;
    triggerClass?: string;
    // A section heading above the items, and more sections after them, each below a divider with its own heading.
    itemsLabel?: ReactNode;
    groups?: MenuGroup[];
    label: string;
    trigger: ReactNode;
    items: MenuItem[];
    choice?: MenuChoice;
    toggles?: { label: string; items: MenuToggle[] };
    // A second single choice, right below the toggles.
    afterToggles?: MenuChoice & { tour?: string };
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
}) {
    return (
        // Not modal: the guided tour opens this menu and its own card must stay clickable beside it.
        <MenuPrimitive.Root open={open} onOpenChange={onOpenChange} modal={false}>
            <MenuPrimitive.Trigger asChild>
                <button type="button" className={triggerClass} aria-label={label}>
                    {trigger}
                </button>
            </MenuPrimitive.Trigger>
            <MenuPrimitive.Portal>
                <MenuPrimitive.Content className="ui-popover ui-menu" align="end" sideOffset={6} collisionPadding={12} data-tour={tour}>
                    {choice && <ChoiceGroup choice={choice} tour="menu-connection" />}
                    {toggles && (
                        <MenuPrimitive.Group data-tour="menu-show">
                            <MenuPrimitive.Label className="ui-menu__label">{toggles.label}</MenuPrimitive.Label>
                            {toggles.items.map((t) => (
                                <MenuPrimitive.CheckboxItem
                                    key={t.label}
                                    className="ui-item ui-item--choice"
                                    checked={t.checked}
                                    onCheckedChange={t.onChange}
                                    // Keep the menu open so several layers can be switched in one go.
                                    onSelect={(e) => e.preventDefault()}
                                >
                                    <span className="ui-item__check">
                                        <MenuPrimitive.ItemIndicator>
                                            <Check />
                                        </MenuPrimitive.ItemIndicator>
                                    </span>
                                    <span className="ui-item__body ui-item__body--row">
                                        {t.label}
                                        <span className="ui-item__end">
                                            {t.icon}
                                            {t.hotkey && <kbd className="kbd-hint">{t.hotkey}</kbd>}
                                        </span>
                                    </span>
                                </MenuPrimitive.CheckboxItem>
                            ))}
                            {(afterToggles || items.length > 0) && <MenuPrimitive.Separator className="ui-menu__separator" />}
                        </MenuPrimitive.Group>
                    )}
                    {afterToggles && <ChoiceGroup choice={afterToggles} tour={afterToggles.tour} separator={items.length > 0} />}
                    {items.length > 0 && (
                    <MenuPrimitive.Group data-tour="menu-actions">
                        {itemsLabel && <MenuPrimitive.Label className="ui-menu__label">{itemsLabel}</MenuPrimitive.Label>}
                        {items.map((item) => (
                            <MenuPrimitive.Item key={item.label} className="ui-item" onSelect={item.onSelect} data-tour={item.tour}>
                                <span className="ui-item__body">
                                    {item.label}
                                    {item.hint && <span className="ui-item__hint">{item.hint}</span>}
                                </span>
                                {item.shortcut && <kbd className="ui-item__kbd">{item.shortcut}</kbd>}
                            </MenuPrimitive.Item>
                        ))}
                    </MenuPrimitive.Group>
                    )}
                    {groups.map((group, i) => (
                        <MenuPrimitive.Group key={i} data-tour={group.tour}>
                            <MenuPrimitive.Separator className="ui-menu__separator" />
                            {group.label && <MenuPrimitive.Label className="ui-menu__label">{group.label}</MenuPrimitive.Label>}
                            {group.items.map((item) => (
                                <MenuPrimitive.Item key={item.label} className="ui-item" onSelect={item.onSelect} data-tour={item.tour}>
                                    <span className="ui-item__body">
                                        {item.label}
                                        {item.hint && <span className="ui-item__hint">{item.hint}</span>}
                                    </span>
                                </MenuPrimitive.Item>
                            ))}
                        </MenuPrimitive.Group>
                    ))}
                </MenuPrimitive.Content>
            </MenuPrimitive.Portal>
        </MenuPrimitive.Root>
    );
}

export function Dialog({ open, onOpenChange, title, description, children, wide }: { open: boolean; onOpenChange: (open: boolean) => void; title: string; description?: string; children: ReactNode; wide?: boolean }) {
    return (
        <DialogPrimitive.Root open={open} onOpenChange={onOpenChange}>
            <DialogPrimitive.Portal>
                <DialogPrimitive.Overlay className="ui-overlay" />
                <DialogPrimitive.Content className={`ui-dialog${wide ? ' ui-dialog--wide' : ''}`} {...(description ? {} : { 'aria-describedby': undefined })}>
                    <div className="ui-dialog__head">
                        <DialogPrimitive.Title className="ui-dialog__title">{title}</DialogPrimitive.Title>
                        <DialogPrimitive.Close className="button button--icon button--ghost" aria-label="Close">
                            <svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true" className="ui-icon">
                                <path d="M4 4 L12 12 M12 4 L4 12" />
                            </svg>
                        </DialogPrimitive.Close>
                    </div>
                    {description && <DialogPrimitive.Description className="ui-dialog__description">{description}</DialogPrimitive.Description>}
                    {children}
                </DialogPrimitive.Content>
            </DialogPrimitive.Portal>
        </DialogPrimitive.Root>
    );
}

export const Tabs = TabsPrimitive.Root;

export function TabList({ label, children }: { label: string; children: ReactNode }) {
    return (
        <TabsPrimitive.List className="ui-tabs" aria-label={label}>
            {children}
        </TabsPrimitive.List>
    );
}

export function Tab({ value, children }: { value: string; children: ReactNode }) {
    return (
        <TabsPrimitive.Trigger value={value} className="ui-tab">
            {children}
        </TabsPrimitive.Trigger>
    );
}

export const TabPanel = TabsPrimitive.Content;
