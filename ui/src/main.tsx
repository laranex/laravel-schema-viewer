import '@xyflow/react/dist/style.css';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import App from './App';
import { schema } from './mock/schema';
import './styles.css';

createRoot(document.getElementById('root')!).render(
    <StrictMode>
        <App doc={schema} />
    </StrictMode>,
);
