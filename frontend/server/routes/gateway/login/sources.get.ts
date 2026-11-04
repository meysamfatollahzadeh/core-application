import { loginApi, noStore } from '../../../utils/login'
import type { UserSourceSummary } from '../../../../shared/types/login'
export default defineEventHandler(event => {
  noStore(event)
  return loginApi<{ data: UserSourceSummary[] }>(event, '/sources')
})
