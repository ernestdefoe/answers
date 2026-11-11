const config = require('flarum-webpack-config');

const NS = 'ernestdefoe-answers';

/*
 * 🚨 Lazy chunks need ids no other extension can have: webpack's default
 * 3-digit ids can collide with another extension's chunk, so the wrong file
 * is fetched or the chunk counts as already loaded. Ids are prefixed with our
 * extension id (same as onair-plus and member-map).
 */
class NamespacedChunkIds {
  apply(compiler) {
    compiler.hooks.compilation.tap('NamespacedChunkIds', (compilation) => {
      compilation.hooks.chunkIds.tap('NamespacedChunkIds', (chunks) => {
        for (const chunk of chunks) {
          if (chunk.id === null) {
            chunk.id = `${NS}:${chunk.name || chunk.debugId}`;
            chunk.ids = [chunk.id];
          }
        }
      });
    });
  }
}

module.exports = (...args) => {
  let base = config();
  if (typeof base === 'function') base = base(...args);

  base.optimization = { ...base.optimization, chunkIds: false };
  base.plugins = [...(base.plugins || []), new NamespacedChunkIds()];

  return base;
};
