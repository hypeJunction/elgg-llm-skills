// jQuery for tests.
//
// Do NOT hand-roll a `$` from document.querySelector: it returns a bare DOM node,
// so the first .on()/.each()/.addClass()/.data() the module under test calls will
// throw — which is nearly all real Elgg plugin JS. Depend on the real library; it
// runs fine under Vitest's jsdom environment.
//
//   npm i -D jquery
//
// On Elgg 7.x jQuery is a DEFERRED ESM module and no longer an automatic global,
// so anything reaching for `jQuery(` or `window.jQuery` needs it exposed first.
import jq from 'jquery';

const $ = jq(globalThis.window);

globalThis.$ = $;
globalThis.jQuery = $;

export default $;
