<template>
  <figure class="stats-chart">
    <div class="stats-chart-scroll">
      <svg :viewBox="`0 0 ${W} ${H}`" class="stats-chart-svg" :style="{ minWidth: horizontal ? '28rem' : '36rem' }" role="img" :aria-label="summary">
        <title>{{ title }}</title>
        <g v-for="tick in ticks" :key="tick.value">
          <line class="stats-grid" :x1="tick.x1" :y1="tick.y1" :x2="tick.x2" :y2="tick.y2" />
          <text class="stats-axis" :x="tick.tx" :y="tick.ty" :text-anchor="tick.anchor">{{ format(tick.value) }}</text>
        </g>
        <g v-for="bar in bars" :key="bar.label">
          <rect
            v-for="seg in bar.segments"
            :key="seg.series"
            :class="`stats-bar stats-series-${seg.series}`"
            :x="seg.x" :y="seg.y" :width="seg.w" :height="seg.h"
          ><title>{{ bar.label }} : {{ format(seg.value) }} {{ seriesNames[seg.series] }}</title></rect>
          <text v-if="bar.showLabel" class="stats-axis" :x="bar.lx" :y="bar.ly" :text-anchor="horizontal ? 'end' : 'middle'">{{ bar.label }}</text>
          <text v-if="horizontal" class="stats-value" :x="bar.vx" :y="bar.ly">{{ format(bar.total) }}</text>
        </g>
      </svg>
    </div>
    <figcaption class="stats-legend">
      <span v-for="(s, i) in series" :key="s.name" class="stats-legend-item"><span :class="`stats-swatch stats-series-${i}`" aria-hidden="true"></span>{{ s.name }}</span>
      <span class="sr-only">{{ summary }}</span>
    </figcaption>
  </figure>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  title: { type: String, required: true },
  labels: { type: Array, required: true },
  /** [{ name, values: number[] }], stacked in order. */
  series: { type: Array, required: true },
  horizontal: { type: Boolean, default: false },
});

const W = 720;
const format = (n) => new Intl.NumberFormat('fr-FR').format(n);
const seriesNames = computed(() => props.series.map((s) => s.name.toLowerCase()));
const totals = computed(() => props.labels.map((_, i) => props.series.reduce((sum, s) => sum + (s.values[i] || 0), 0)));

/** Smallest "round" number (1, 2, 5 × 10^n) at or above the value. */
function niceMax(value) {
  if (value <= 0) return 1;
  const pow = 10 ** Math.floor(Math.log10(value));
  return [1, 2, 5, 10].map((m) => m * pow).find((c) => c >= value);
}
const max = computed(() => niceMax(Math.max(0, ...totals.value)));

const ROW = 26;
const H = computed(() => (props.horizontal ? props.labels.length * ROW + 30 : 300));
const box = computed(() => (props.horizontal ? { l: 130, r: 56, t: 4, b: 24 } : { l: 48, r: 8, t: 8, b: 28 }));
const plotW = computed(() => W - box.value.l - box.value.r);
const plotH = computed(() => H.value - box.value.t - box.value.b);

const ticks = computed(() =>
  [0, 1, 2, 3, 4].map((i) => {
    const value = (max.value / 4) * i;
    const p = (value / max.value) * (props.horizontal ? plotW.value : plotH.value);
    return props.horizontal
      ? { value, x1: box.value.l + p, x2: box.value.l + p, y1: box.value.t, y2: box.value.t + plotH.value, tx: box.value.l + p, ty: H.value - 8, anchor: 'middle' }
      : { value, x1: box.value.l, x2: box.value.l + plotW.value, y1: box.value.t + plotH.value - p, y2: box.value.t + plotH.value - p, tx: box.value.l - 6, ty: box.value.t + plotH.value - p + 4, anchor: 'end' };
  }),
);

const bars = computed(() => {
  const n = props.labels.length;
  const band = (props.horizontal ? plotH.value : plotW.value) / Math.max(1, n);
  const thickness = band * 0.7;
  const every = props.horizontal ? 1 : Math.ceil(n / 12);
  return props.labels.map((label, i) => {
    let offset = 0;
    const segments = [];
    props.series.forEach((s, series) => {
      const value = s.values[i] || 0;
      const length = (value / max.value) * (props.horizontal ? plotW.value : plotH.value);
      if (value > 0) {
        segments.push(
          props.horizontal
            ? { series, value, x: box.value.l + offset, y: box.value.t + i * band + (band - thickness) / 2, w: length, h: thickness }
            : { series, value, x: box.value.l + i * band + (band - thickness) / 2, y: box.value.t + plotH.value - offset - length, w: thickness, h: length },
        );
      }
      offset += length;
    });
    return props.horizontal
      ? { label, segments, total: totals.value[i], showLabel: true, lx: box.value.l - 8, ly: box.value.t + i * band + band / 2 + 4, vx: box.value.l + offset + 6 }
      : { label, segments, total: totals.value[i], showLabel: i % every === 0, lx: box.value.l + i * band + band / 2, ly: H.value - 10 };
  });
});

const summary = computed(() => `${props.title} : ${props.labels.map((l, i) => `${l} ${format(totals.value[i])}`).join(', ')}.`);
</script>
