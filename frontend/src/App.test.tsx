import { render, screen } from '@testing-library/react'
import { App } from './App'

describe('App', () => {
  it('renderiza el título', () => {
    render(<App />)
    expect(screen.getByRole('heading', { level: 1, name: 'PyME Contable' })).toBeInTheDocument()
  })
})