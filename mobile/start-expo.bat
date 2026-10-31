@echo off
cd /d C:\laragon\www\rted_lra\mobile
REM No hardcoded EXPO_PUBLIC_API_BASE here: the app derives the API host from the
REM Expo dev server address the phone connected to, so it survives DHCP IP changes.
REM To target something else (e.g. an Android emulator at 10.0.2.2, or the deployed
REM API) set EXPO_PUBLIC_API_BASE before starting.
npx expo start --clear