# React + Vite

This template provides a minimal setup to get React working in Vite with HMR and some ESLint rules.

Currently, two official plugins are available:

- [@vitejs/plugin-react](https://github.com/vitejs/vite-plugin-react/blob/main/packages/plugin-react) uses [Oxc](https://oxc.rs)
- [@vitejs/plugin-react-swc](https://github.com/vitejs/vite-plugin-react/blob/main/packages/plugin-react-swc) uses [SWC](https://swc.rs/)

## React Compiler

The React Compiler is not enabled on this template because of its impact on dev & build performances. To add it, see [this documentation](https://react.dev/learn/react-compiler/installation).

## Expanding the ESLint configuration

If you are developing a production application, we recommend using TypeScript with type-aware lint rules enabled. Check out the [TS template](https://github.com/vitejs/vite/tree/main/packages/create-vite/template-react-ts) for information on how to integrate TypeScript and [`typescript-eslint`](https://typescript-eslint.io) in your project.

## Backend API Configuration

The React frontend uses `VITE_API_BASE_URL` to construct backend API requests in production.
- Set `VITE_API_BASE_URL` to your PHP backend root URL when the frontend and backend are deployed separately.
- Leave it blank when the frontend is served from the same origin as the PHP backend.
- In Render, configure this variable in the frontend service environment settings and rebuild/deploy the app.
- For local development, create `greengrow-frontend/.env` with:

```text
VITE_API_BASE_URL=
```

This keeps local requests proxied through Vite to `http://localhost:8000`.

## Deploying on Render

If the React frontend is hosted on Render and the PHP backend is hosted separately, do the following:
1. In the Render frontend service dashboard, add an environment variable:
   - `VITE_API_BASE_URL=https://<your-backend-service>.onrender.com`
2. Rebuild and deploy the frontend service.
3. Ensure the backend URL is publicly accessible and that the backend service supports CORS if needed.

When the frontend and backend are on the same origin, you do not need to set `VITE_API_BASE_URL`.
