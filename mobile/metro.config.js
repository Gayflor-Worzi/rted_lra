const { getDefaultConfig } = require('expo/metro-config')

const config = getDefaultConfig(__dirname)

config.resolver.extraNodeModules = {
  buffer: require.resolve('buffer/'),
}

config.resolver.assetExts = [...config.resolver.assetExts, 'wasm']

module.exports = config
