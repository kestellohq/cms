import { computed, onBeforeUnmount, onMounted, ref, unref, useSSRContext } from "vue";
import { ssrInterpolate, ssrRenderAttr, ssrRenderClass, ssrRenderComponent, ssrRenderList } from "vue/server-renderer";
import { Head } from "@kestello/app";
// content/themes/base/resources/js/pages/Home.vue
var _sfc_main = /*@__PURE__*/ Object.assign({ layout: "Site" }, {
	__name: "Home",
	__ssrInlineRender: true,
	setup(__props) {
		const sample = {
			name: "Harbour & Pine",
			pitch: "Sourdough, pastries and coffee by the quay, baked from seven every morning.",
			number: "14",
			street: "Quay Street",
			town: "Old Harbour",
			mapUrl: "https://www.openstreetmap.org/search?query=14%20Quay%20Street",
			phone: "+44 20 7946 0321",
			email: "hello@harbourpine.example",
			closures: ["2026-11-10"],
			hours: {
				0: ["08:00", "14:00"],
				1: null,
				2: ["07:00", "18:00"],
				3: ["07:00", "18:00"],
				4: ["07:00", "18:00"],
				5: ["07:00", "18:00"],
				6: ["08:00", "17:00"]
			},
			counter: [
				{
					name: "Country sourdough",
					note: "Three-day ferment, 900 g",
					price: "6.50"
				},
				{
					name: "Cardamom bun",
					note: "Our Saturday queue starter",
					price: "3.80"
				},
				{
					name: "Rye & fennel loaf",
					note: "Dense, dark, keeps all week",
					price: "7.20"
				},
				{
					name: "Flat white",
					note: "Beans from Hill Street Roasters",
					price: "3.60"
				}
			],
			notice: {
				title: "Closed Tuesday 10 November for the oven service.",
				body: "Pre-order Saturday loaves by Thursday at the counter."
			},
			story: "Two bakers, one wood-fired oven and a corner of the old harbour. We bake in small batches and sell out most days by two, so come early or ask us to set a loaf aside."
		};
		const DAY_NAMES = [
			"Sunday",
			"Monday",
			"Tuesday",
			"Wednesday",
			"Thursday",
			"Friday",
			"Saturday"
		];
		const ORDER = [
			2,
			3,
			4,
			5,
			6,
			0,
			1
		];
		const minutes = (hhmm) => {
			const [h, m] = hhmm.split(":").map(Number);
			return h * 60 + m;
		};
		const now = ref(null);
		const flipping = ref(false);
		let timer = null;
		const status = computed(() => {
			if (now.value === null) return null;
			const t = now.value.getHours() * 60 + now.value.getMinutes();
			const slotOn = (offset) => {
				const date = new Date(now.value.getFullYear(), now.value.getMonth(), now.value.getDate() + offset);
				const key = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
				return {
					day: date.getDay(),
					slot: sample.closures.includes(key) ? null : sample.hours[date.getDay()]
				};
			};
			const today = slotOn(0).slot;
			if (today && t >= minutes(today[0]) && t < minutes(today[1])) return {
				open: true,
				when: `until ${today[1]} today`
			};
			for (let i = 0; i < 14; i++) {
				const { day: d, slot } = slotOn(i);
				if (slot && (i > 0 || t < minutes(slot[0]))) return {
					open: false,
					when: i === 0 ? `opens at ${slot[0]}` : i === 1 ? `opens tomorrow at ${slot[0]}` : `opens ${DAY_NAMES[d]} at ${slot[0]}`
				};
			}
			return {
				open: false,
				when: "see opening hours"
			};
		});
		const todayIndex = computed(() => now.value ? now.value.getDay() : -1);
		function tick() {
			const before = status.value?.open;
			now.value = /* @__PURE__ */ new Date();
			if (before !== void 0 && before !== status.value?.open) flip();
		}
		function flip() {
			flipping.value = false;
			requestAnimationFrame(() => {
				flipping.value = true;
			});
		}
		onMounted(() => {
			tick();
			flip();
			timer = setInterval(tick, 3e4);
		});
		onBeforeUnmount(() => clearInterval(timer));
		const hoursRows = ORDER.map((d) => ({
			day: d,
			label: d === 1 ? "Monday" : DAY_NAMES[d],
			value: sample.hours[d] ? `${sample.hours[d][0]}–${sample.hours[d][1]}` : "Closed"
		}));
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			_push(ssrRenderComponent(unref(Head), { title: sample.name }, null, _parent));
			_push(`<div class="site-wrap"><div class="wall"><section class="plate p-name" aria-labelledby="name"><i class="plate__screw"></i><i class="plate__screw"></i><i class="plate__screw"></i><i class="plate__screw"></i><h1 id="name">${ssrInterpolate(sample.name)}</h1><p>${ssrInterpolate(sample.pitch)}</p></section><section class="${ssrRenderClass([[status.value ? status.value.open ? "plate--open" : "plate--closed" : "plate--deep", { "is-flipping": flipping.value }], "plate p-open"])}" aria-live="polite"><i class="plate__screw"></i><i class="plate__screw"></i><i class="plate__screw"></i><i class="plate__screw"></i>`);
			if (status.value) _push(`<!--[--><span class="p-open__word">${ssrInterpolate(status.value.open ? "OPEN" : "CLOSED")}</span><span class="p-open__when">${ssrInterpolate(status.value.when)}</span><!--]-->`);
			else _push(`<!--[--><span class="p-open__word">HOURS</span><span class="p-open__when">Tue–Fri 07:00–18:00</span><!--]-->`);
			_push(`</section><section class="plate plate--deep p-number" aria-label="Address"><i class="plate__screw"></i><i class="plate__screw"></i><i class="plate__screw"></i><i class="plate__screw"></i><b>${ssrInterpolate(sample.number)}</b><span>${ssrInterpolate(sample.street)}</span><small>${ssrInterpolate(sample.town)}</small><a class="plate-action"${ssrRenderAttr("href", sample.mapUrl)} target="_blank" rel="noopener noreferrer"> Find us <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 10h11M11 5l5 5-5 5"></path></svg><span class="k-sr-only" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);">(opens a map in a new tab)</span></a></section><section class="plate plate--light p-menu" aria-labelledby="counter"><i class="plate__screw"></i><i class="plate__screw"></i><i class="plate__screw"></i><i class="plate__screw"></i><h2 id="counter">Today at the counter</h2><ul><!--[-->`);
			ssrRenderList(sample.counter, (item) => {
				_push(`<li><span>${ssrInterpolate(item.name)}<small>${ssrInterpolate(item.note)}</small></span><b>${ssrInterpolate(item.price)}</b></li>`);
			});
			_push(`<!--]--></ul></section><section class="plate plate--notice p-notice" aria-labelledby="notice"><i class="plate__screw"></i><i class="plate__screw"></i><i class="plate__screw"></i><i class="plate__screw"></i><h2 id="notice">${ssrInterpolate(sample.notice.title)}</h2><p>${ssrInterpolate(sample.notice.body)}</p></section><section class="plate p-hours" aria-labelledby="hours"><i class="plate__screw"></i><i class="plate__screw"></i><i class="plate__screw"></i><i class="plate__screw"></i><h2 id="hours">Opening hours</h2><dl><!--[-->`);
			ssrRenderList(unref(hoursRows), (row) => {
				_push(`<div class="${ssrRenderClass({ "is-today": row.day === todayIndex.value })}"><dt>${ssrInterpolate(row.label)}</dt><dd>${ssrInterpolate(row.value)}</dd></div>`);
			});
			_push(`<!--]--></dl></section><section class="plate plate--light p-story" aria-labelledby="story"><i class="plate__screw"></i><i class="plate__screw"></i><i class="plate__screw"></i><i class="plate__screw"></i><h2 id="story">Our story</h2><p>${ssrInterpolate(sample.story)}</p></section><section class="plate p-contact" aria-labelledby="contact"><i class="plate__screw"></i><i class="plate__screw"></i><i class="plate__screw"></i><i class="plate__screw"></i><h2 id="contact">Set a loaf aside</h2><p>Call or write before 10:00 and we will keep one for you.</p><a${ssrRenderAttr("href", `tel:${sample.phone.replace(/\s/g, "")}`)}>${ssrInterpolate(sample.phone)}</a><a${ssrRenderAttr("href", `mailto:${sample.email}`)}>${ssrInterpolate(sample.email)}</a></section></div></div><!--]-->`);
		};
	}
});
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/Home.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
