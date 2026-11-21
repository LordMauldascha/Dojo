import '@sulu/web';
import { preventDoubleSubmit } from './preventDoubleSubmit';
import { smashLogout } from './smashLogout';
import { rollAwayAfterLoad, rollInOnLogin } from './tatamiLogin';

preventDoubleSubmit();
smashLogout();
rollInOnLogin();
rollAwayAfterLoad();
