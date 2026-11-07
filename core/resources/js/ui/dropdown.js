/**
 * Where a dropdown (KPicker's results) goes beside the box it belongs to, in viewport px for
 * position: fixed, so a card, a scrolling panel or a dialog that clips what it holds never
 * cuts the list off. Under the box while a useful height fits there, else on the side with
 * more room; never taller than that room; as wide as the box.
 *
 * @param {{top:number,bottom:number,left:number,width:number}} anchor  the box (getBoundingClientRect)
 * @param {{width:number,height:number}} viewport
 * @param {{gap?:number, maxHeight?:number, margin?:number}} [options]  margin: kept free at the screen's edge
 * @returns {{left:number,width:number,maxHeight:number,up:boolean,top?:number,bottom?:number}}
 */
export function dropdownPlace(anchor, viewport, { gap = 4, maxHeight = 280, margin = 8 } = {}) {
    const below = viewport.height - anchor.bottom - gap - margin;
    const above = anchor.top - gap - margin;
    const up = below < Math.min(maxHeight, 160) && above > below;
    const room = Math.max(0, up ? above : below);
    const side = up ? { bottom: viewport.height - anchor.top + gap } : { top: anchor.bottom + gap };
    return { ...side, left: anchor.left, width: anchor.width, maxHeight: Math.min(maxHeight, room), up };
}
